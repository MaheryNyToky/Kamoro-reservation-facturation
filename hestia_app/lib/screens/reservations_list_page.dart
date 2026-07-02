import 'dart:convert';

import 'package:flutter/material.dart';
import 'package:http/http.dart' as http;
import 'package:shared_preferences/shared_preferences.dart';

import '../core/app_config.dart';
import '../core/formatters.dart';
import '../models/reservation.dart';
import '../services/api_client.dart';
import 'checkin_page.dart';
import 'folio_page.dart';

const String baseUrl = AppConfig.apiBaseUrl;
const Color _primary = Color(0xFF0F766E);
const Color _primaryDark = Color(0xFF134E4A);
const Color _ink = Color(0xFF0F172A);
const Color _muted = Color(0xFF64748B);
const Color _border = Color(0xFFE2E8F0);
const Color _rose = Color(0xFFBE123C);

Route<T> _reservationRoute<T>(Widget page) {
  return PageRouteBuilder<T>(
    pageBuilder: (_, _, _) => page,
    transitionDuration: const Duration(milliseconds: 360),
    reverseTransitionDuration: const Duration(milliseconds: 260),
    transitionsBuilder: (_, animation, _, child) {
      final curved = CurvedAnimation(
        parent: animation,
        curve: Curves.easeOutCubic,
        reverseCurve: Curves.easeInCubic,
      );
      return FadeTransition(
        opacity: curved,
        child: SlideTransition(
          position: Tween<Offset>(
            begin: const Offset(0.025, 0.02),
            end: Offset.zero,
          ).animate(curved),
          child: child,
        ),
      );
    },
  );
}

class ReservationsListPage extends StatefulWidget {
  const ReservationsListPage({
    super.key,
    required this.role,
    required this.userName,
    this.initialDate,
  });

  final String role;
  final String userName;
  final DateTime? initialDate;
  @override
  State<ReservationsListPage> createState() => _ReservationsListPageState();
}

class EditReservationPage extends StatefulWidget {
  const EditReservationPage({
    super.key,
    required this.reservation,
    required this.userName,
    required this.role,
  });

  final Map<String, dynamic> reservation;
  final String userName;
  final String role;

  @override
  State<EditReservationPage> createState() => _EditReservationPageState();
}

class _EditReservationPageState extends State<EditReservationPage> {
  final _apiClient = const ApiClient();
  final _formKey = GlobalKey<FormState>();
  late Reservation _reservation;
  late final TextEditingController _nameController;
  late final TextEditingController _phoneController;
  late final TextEditingController _emailController;
  late DateTime _checkIn;
  late DateTime _checkOut;
  late int _extraBeds;
  late int _extraMattresses;
  int _remainingExtraBeds = 6;
  int _remainingExtraMattresses = 6;
  final List<Map<String, dynamic>> _selectedRooms = [];
  final Map<int, _RoomSegmentDraft> _segmentDrafts = {};
  final Set<int> _initialRoomIds = {};
  List<Map<String, dynamic>> _availableRooms = [];
  String _roomSearchQuery = '';
  bool _showRoomsNeedingSplit = false;
  bool _isLoadingRooms = true;
  bool _isSaving = false;
  String? _errorMessage;

  bool get _isPostCheckIn =>
      (widget.reservation['status'] ?? '').toString() == 'arrive';

  bool get _canEditCheckIn => widget.role != 'receptionist' && !_isPostCheckIn;

  @override
  void initState() {
    super.initState();
    _reservation = Reservation.fromJson(widget.reservation);
    _nameController = TextEditingController(text: _reservation.clientName);
    _phoneController = TextEditingController(text: _reservation.phone);
    _emailController = TextEditingController(text: _reservation.email);
    _checkIn = _reservation.checkIn;
    _checkOut = _reservation.checkOut;
    _extraBeds = _reservation.extraBeds;
    _extraMattresses = _reservation.extraMattresses;
    _selectedRooms.addAll(_initialRooms());
    _seedInitialRoomIds();
    _refreshReservationSnapshot();
  }

  @override
  void dispose() {
    _nameController.dispose();
    _phoneController.dispose();
    _emailController.dispose();
    super.dispose();
  }

  List<Map<String, dynamic>> _initialRooms() {
    final details = widget.reservation['room_details'];
    if (details is List) {
      return details
          .whereType<Map>()
          .map((room) => Map<String, dynamic>.from(room))
          .toList();
    }
    return [];
  }

  void _seedInitialRoomIds() {
    _initialRoomIds
      ..clear()
      ..addAll(_reservation.roomIds)
      ..addAll(_initialRooms().map((room) => _asInt(room['id'])));
  }

  int _calculateRoomNightPrice() {
    return _selectedRooms.fold<int>(
      0,
      (total, room) => total + _getRoomPrice(room),
    );
  }

  int _calculateRoomPrice() {
    var total = 0;
    for (final room in _selectedRooms) {
      final draft =
          _segmentDrafts[_asInt(room['id'])] ?? _segmentDraftFromRoom(room);
      total += _getRoomPrice(room) * _segmentNights(draft);
    }
    return total;
  }

  int _calculateExtrasNightPrice() {
    return _selectedRooms.fold<int>(0, (total, room) {
      final draft =
          _segmentDrafts[_asInt(room['id'])] ?? _segmentDraftFromRoom(room);
      return total +
          (draft.extraBeds * 50000) +
          (draft.extraMattresses * 35000);
    });
  }

  int _calculateExtrasPrice() {
    var total = 0;
    for (final room in _selectedRooms) {
      final draft =
          _segmentDrafts[_asInt(room['id'])] ?? _segmentDraftFromRoom(room);
      total +=
          ((draft.extraBeds * 50000) + (draft.extraMattresses * 35000)) *
          _segmentNights(draft);
    }
    return total;
  }

  int _segmentNights(_RoomSegmentDraft draft) {
    final start = DateTime(
      draft.startDate.year,
      draft.startDate.month,
      draft.startDate.day,
    );
    final end = DateTime(
      draft.endDate.year,
      draft.endDate.month,
      draft.endDate.day,
    );
    final nights = end.difference(start).inDays;
    return nights < 1 ? 1 : nights;
  }

  int _calculateTotalPrice() {
    return _calculateRoomPrice() + _calculateExtrasPrice();
  }

  Set<String> _initialRoomNumbers() {
    final rawNumbers = widget.reservation['room_numbers'];
    if (rawNumbers is String && rawNumbers.trim().isNotEmpty) {
      return rawNumbers
          .split(',')
          .map((value) => value.trim())
          .where((value) => value.isNotEmpty)
          .toSet();
    }
    return const <String>{};
  }

  Future<void> _refreshReservationSnapshot() async {
    try {
      final response = await _apiClient.get('/api/reservations/all', {
        'date': 'all',
      }, const Duration(seconds: 6));

      if (response.statusCode != 200) {
        await _fetchRooms();
        return;
      }

      final decoded = json.decode(response.body);
      if (decoded is! List) {
        await _fetchRooms();
        return;
      }

      final fresh = decoded
          .whereType<Map>()
          .map((item) => Map<String, dynamic>.from(item))
          .firstWhere(
            (item) => _asInt(item['id']) == _reservation.id,
            orElse: () => widget.reservation,
          );

      if (!mounted) return;
      setState(() {
        _reservation = Reservation.fromJson(fresh);
        _nameController.text = _reservation.clientName;
        _phoneController.text = _reservation.phone;
        _emailController.text = _reservation.email;
        _checkIn = _reservation.checkIn;
        _checkOut = _reservation.checkOut;
        _extraBeds = _reservation.extraBeds;
        _extraMattresses = _reservation.extraMattresses;
        _selectedRooms
          ..clear()
          ..addAll(_initialRoomsFromMap(fresh));
        _seedInitialRoomIds();
        _syncSegmentDraftsFromRooms(_selectedRooms);
      });
    } catch (_) {
      // On garde les données locales si la requête fraîche échoue.
    } finally {
      _fetchRooms();
    }
  }

  List<Map<String, dynamic>> _initialRoomsFromMap(Map<String, dynamic> source) {
    final details = source['room_details'];
    if (details is List) {
      return details
          .whereType<Map>()
          .map((room) => Map<String, dynamic>.from(room))
          .toList();
    }
    return [];
  }

  void _syncSegmentDraftsFromRooms(List<Map<String, dynamic>> rooms) {
    final roomIds = rooms.map((room) => _asInt(room['id'])).toSet();
    _segmentDrafts.removeWhere((roomId, _) => !roomIds.contains(roomId));
    for (final room in rooms) {
      _segmentDrafts[_asInt(room['id'])] = _segmentDraftFromRoom(room);
    }
    final hasSegmentExtras = rooms.any(
      (room) =>
          _asInt(room['segment_extra_beds']) > 0 ||
          _asInt(room['segment_extra_mattresses']) > 0,
    );
    if (!hasSegmentExtras && rooms.isNotEmpty) {
      final firstRoomId = _asInt(rooms.first['id']);
      final firstDraft = _segmentDrafts[firstRoomId];
      if (firstDraft != null) {
        firstDraft.extraBeds = _reservation.extraBeds;
        firstDraft.extraMattresses = _reservation.extraMattresses;
      }
    }
  }

  _RoomSegmentDraft _segmentDraftFromRoom(Map<String, dynamic> room) {
    final roomId = _asInt(room['id']);
    final roomLabel = _roomLabel(room);
    return _RoomSegmentDraft(
      roomId: roomId,
      roomLabel: roomLabel,
      startDate: _parseDate(room['segment_start_date']) ?? _checkIn,
      endDate: _parseDate(room['segment_end_date']) ?? _checkOut,
      extraBeds: _asInt(room['segment_extra_beds'] ?? 0),
      extraMattresses: _asInt(room['segment_extra_mattresses'] ?? 0),
    );
  }

  _RoomSegmentDraft _defaultSegmentDraft(Map<String, dynamic> room) {
    final draft = _segmentDraftFromRoom(room);
    final segments = _roomAvailabilitySegments(room);
    if (room['is_fully_available'] == true || segments.isEmpty) {
      draft.startDate = _checkIn;
      draft.endDate = _checkOut;
    } else {
      final firstSegment = segments.first;
      draft.startDate =
          _parseDate(firstSegment['segment_start_date']) ?? _checkIn;
      draft.endDate = _parseDate(firstSegment['segment_end_date']) ?? _checkOut;
    }
    draft.extraBeds = 0;
    draft.extraMattresses = 0;
    return draft;
  }

  String _roomLabel(Map<String, dynamic> room) {
    final roomNumber = (room['room_number'] ?? room['number'] ?? room['id'])
        .toString()
        .trim();
    final type = (room['type'] ?? '').toString().trim();
    final model = (room['model'] ?? '').toString().trim();
    final base = roomNumber.isEmpty
        ? [type, model].where((value) => value.isNotEmpty).join(' - ')
        : (type.isEmpty ? roomNumber : '$roomNumber - $type');
    if (base.isEmpty) return 'Chambre';
    return model.isEmpty ? base : '$base ($model)';
  }

  DateTime? _parseDate(dynamic value) {
    final text = value?.toString().trim();
    if (text == null || text.isEmpty || text == 'null') return null;
    return DateTime.tryParse(text);
  }

  DateTime _dateOnly(DateTime value) =>
      DateTime(value.year, value.month, value.day);

  String _dateLabel(DateTime value) {
    final day = value.day.toString().padLeft(2, '0');
    final month = value.month.toString().padLeft(2, '0');
    return '$day/$month/${value.year}';
  }

  List<Map<String, dynamic>> _roomAvailabilitySegments(
    Map<String, dynamic> room,
  ) {
    final raw = room['availability_segments'];
    if (raw is! Iterable) {
      return [
        {'segment_start_date': _checkInKey, 'segment_end_date': _checkOutKey},
      ];
    }
    return raw
        .whereType<Map>()
        .map((segment) => Map<String, dynamic>.from(segment))
        .where((segment) {
          final start = _parseDate(segment['segment_start_date']);
          final end = _parseDate(segment['segment_end_date']);
          return start != null && end != null && start.isBefore(end);
        })
        .toList();
  }

  String _availabilityLabel(Map<String, dynamic> room) {
    final segments = _roomAvailabilitySegments(room);
    if (segments.isEmpty) return 'Indisponible sur la période';
    if (room['is_fully_available'] == true) {
      return 'Disponible sur tout le séjour';
    }
    final labels = segments
        .map((segment) {
          final start = _parseDate(segment['segment_start_date']);
          final end = _parseDate(segment['segment_end_date']);
          if (start == null || end == null) return '';
          return '${_dateLabel(start)} -> ${_dateLabel(end)}';
        })
        .where((label) => label.isNotEmpty);
    return 'Libre: ${labels.join(' • ')}';
  }

  bool _shouldDisplayRoom(Map<String, dynamic> room) {
    final roomId = _asInt(room['id']);
    final isSelected = _selectedRooms.any(
      (selected) => _asInt(selected['id']) == roomId,
    );
    if (isSelected || _initialRoomIds.contains(roomId)) return true;
    if (room['is_fully_available'] == true) return true;
    return _showRoomsNeedingSplit && _roomAvailabilitySegments(room).isNotEmpty;
  }

  List<Map<String, dynamic>> _roomSegmentsPayload() {
    return _selectedRooms.map((room) {
      final draft =
          _segmentDrafts[_asInt(room['id'])] ?? _segmentDraftFromRoom(room);
      return {
        'room_id': draft.roomId,
        'segment_start_date': draft.startDate
            .toIso8601String()
            .split('T')
            .first,
        'segment_end_date': draft.endDate.toIso8601String().split('T').first,
        'segment_extra_beds': draft.extraBeds,
        'segment_extra_mattresses': draft.extraMattresses,
      };
    }).toList();
  }

  void _sortRoomsBySelection(List<Map<String, dynamic>> rooms) {
    rooms.sort((a, b) {
      final aSelected = _selectedRooms.any(
        (selected) => _asInt(selected['id']) == _asInt(a['id']),
      );
      final bSelected = _selectedRooms.any(
        (selected) => _asInt(selected['id']) == _asInt(b['id']),
      );

      if (aSelected != bSelected) {
        return aSelected ? -1 : 1;
      }

      return (a['room_number'] ?? '').toString().compareTo(
        (b['room_number'] ?? '').toString(),
      );
    });
  }

  String get _checkInKey => _checkIn.toIso8601String().substring(0, 10);
  String get _checkOutKey => _checkOut.toIso8601String().substring(0, 10);

  Future<void> _fetchRooms() async {
    setState(() {
      _isLoadingRooms = true;
      _errorMessage = null;
    });

    final cacheKey =
        'available_rooms:$_checkInKey:$_checkOutKey:${_reservation.id}';

    try {
      final response = await _apiClient
          .get('/api/available-room-suggestions', {
            'check_in': _checkInKey,
            'check_out': _checkOutKey,
            'exclude_reservation_id': _reservation.id.toString(),
          })
          .timeout(const Duration(seconds: 4));
      if (response.statusCode == 200) {
        final decoded = json.decode(response.body) as List<dynamic>;
        final rooms = decoded
            .whereType<Map>()
            .map((room) => Map<String, dynamic>.from(room))
            .where(
              (room) =>
                  _roomAvailabilitySegments(room).isNotEmpty ||
                  _initialRoomIds.contains(_asInt(room['id'])) ||
                  _selectedRooms.any(
                    (selected) => _asInt(selected['id']) == _asInt(room['id']),
                  ),
            )
            .toList();
        _seedSelectedRoomsFromAvailable(rooms);
        final availableIds = rooms
            .where((room) => _roomAvailabilitySegments(room).isNotEmpty)
            .map((room) => _asInt(room['id']))
            .toSet();
        final removedUnavailable = _selectedRooms.any(
          (room) => !availableIds.contains(_asInt(room['id'])),
        );
        _selectedRooms.removeWhere(
          (room) => !availableIds.contains(_asInt(room['id'])),
        );
        _segmentDrafts.removeWhere(
          (roomId, _) => !availableIds.contains(roomId),
        );

        _sortRoomsBySelection(rooms);

        if (mounted) {
          setState(() {
            _availableRooms = rooms;
            if (removedUnavailable) {
              _errorMessage =
                  'Certaines chambres sélectionnées ne sont plus disponibles sur ces dates.';
            }
          });
        }
        await _fetchExtraCapacity();
        final prefs = await SharedPreferences.getInstance();
        await prefs.setString(cacheKey, json.encode(rooms));
      } else if (mounted) {
        final decoded = response.body.isNotEmpty
            ? json.decode(response.body)
            : null;
        final message = decoded is Map && decoded['message'] != null
            ? decoded['message'].toString()
            : response.statusCode == 422
            ? 'Dates de réservation invalides.'
            : 'Impossible de charger les chambres.';
        setState(() => _errorMessage = message);
      }
    } catch (e) {
      final prefs = await SharedPreferences.getInstance();
      final cached = prefs.getString(cacheKey);
      if (cached != null) {
        final decoded = json.decode(cached) as List<dynamic>;
        final rooms = decoded
            .whereType<Map>()
            .map((room) => Map<String, dynamic>.from(room))
            .toList();
        _seedSelectedRoomsFromAvailable(rooms);
        _sortRoomsBySelection(rooms);
        if (mounted) {
          setState(() {
            _availableRooms = rooms;
            _errorMessage =
                'Connexion instable: dernières chambres disponibles affichées.';
          });
        }
        await _fetchExtraCapacity();
      } else if (mounted) {
        setState(() => _errorMessage = 'Erreur réseau : $e');
      }
    } finally {
      if (mounted) {
        setState(() => _isLoadingRooms = false);
      }
    }
  }

  Future<void> _fetchExtraCapacity() async {
    try {
      final response = await _apiClient
          .get('/api/dashboard/extras-capacity', {
            'check_in': _checkInKey,
            'check_out': _checkOutKey,
            'exclude_reservation_id': _reservation.id.toString(),
          })
          .timeout(const Duration(seconds: 4));

      if (response.statusCode != 200) return;

      final decoded = json.decode(response.body);
      if (decoded is! Map<String, dynamic>) return;

      final remainingBeds = decoded['remaining_beds'];
      final remainingMattresses = decoded['remaining_mattresses'];

      if (!mounted) return;
      setState(() {
        _remainingExtraBeds = remainingBeds is num ? remainingBeds.toInt() : 6;
        _remainingExtraMattresses = remainingMattresses is num
            ? remainingMattresses.toInt()
            : 6;
        if (_extraBeds > _remainingExtraBeds) {
          _extraBeds = _remainingExtraBeds;
        }
        if (_extraMattresses > _remainingExtraMattresses) {
          _extraMattresses = _remainingExtraMattresses;
        }
      });
    } catch (_) {
      // On garde la dernière capacité connue.
    }
  }

  int _asInt(dynamic value) {
    if (value is int) return value;
    if (value is num) return value.toInt();
    return int.tryParse(value?.toString() ?? '') ?? 0;
  }

  int _getRoomPrice(Map<String, dynamic> room) {
    return _asInt(room['price_snapshot_ariary'] ?? room['base_price_ariary']);
  }

  void _seedSelectedRoomsFromAvailable(List<Map<String, dynamic>> rooms) {
    final initialRoomNumbers = _initialRoomNumbers();
    for (final room in rooms) {
      final roomId = _asInt(room['id']);
      final roomNumber = (room['room_number'] ?? '').toString();
      final shouldBeSelected = _initialRoomIds.contains(roomId);
      final shouldBeSelectedByNumber =
          initialRoomNumbers.isNotEmpty &&
          initialRoomNumbers.contains(roomNumber);
      final alreadySelected = _selectedRooms.any(
        (selected) => _asInt(selected['id']) == roomId,
      );
      if ((shouldBeSelected || shouldBeSelectedByNumber) && !alreadySelected) {
        _initialRoomIds.add(roomId);
        _selectedRooms.add(room);
        _segmentDrafts[roomId] = _defaultSegmentDraft(room);
      }
    }
  }

  bool _matchesRoomSearch(Map<String, dynamic> room) {
    final query = _roomSearchQuery.trim().toLowerCase();
    if (query.isEmpty) return true;

    final roomNumber = (room['room_number'] ?? '').toString().toLowerCase();
    final type = (room['type'] ?? '').toString().toLowerCase();
    final model = (room['model'] ?? '').toString().toLowerCase();
    return roomNumber.contains(query) ||
        type.contains(query) ||
        model.contains(query);
  }

  List<Map<String, dynamic>> get _filteredAvailableRooms {
    final rooms = _availableRooms
        .where(_shouldDisplayRoom)
        .where(_matchesRoomSearch)
        .toList();
    _sortRoomsBySelection(rooms);
    return rooms;
  }

  Future<void> _pickCheckIn() async {
    final today = DateTime.now();
    final firstDate = _reservation.checkIn.isBefore(today)
        ? _reservation.checkIn
        : today;
    final selected = await showDatePicker(
      context: context,
      initialDate: _checkIn,
      firstDate: firstDate,
      lastDate: DateTime.now().add(const Duration(days: 365)),
    );
    if (selected == null) return;

    setState(() {
      _checkIn = selected;
      if (!_checkOut.isAfter(_checkIn)) {
        _checkOut = _checkIn.add(const Duration(days: 1));
      }
    });
    _fetchRooms();
  }

  Future<void> _pickCheckOut() async {
    final firstDate = _isPostCheckIn
        ? _reservation.checkOut
        : _checkIn.add(const Duration(days: 1));
    final initialDate = _checkOut.isBefore(firstDate) ? firstDate : _checkOut;
    final selected = await showDatePicker(
      context: context,
      initialDate: initialDate,
      firstDate: firstDate,
      lastDate: DateTime.now().add(const Duration(days: 365)),
    );
    if (selected == null) return;

    final previousCheckOut = _checkOut;
    setState(() {
      _checkOut = selected;
      if (selected.isAfter(previousCheckOut)) {
        for (final draft in _segmentDrafts.values) {
          if (_dateOnly(draft.endDate) == _dateOnly(previousCheckOut)) {
            draft.endDate = selected;
          }
        }
      }
    });
    _fetchRooms();
  }

  Future<void> _saveChanges() async {
    if (!_reservation.isEditable) {
      ScaffoldMessenger.of(context).showSnackBar(
        const SnackBar(
          content: Text(
            'Seules les réservations non terminées peuvent être modifiées.',
          ),
          backgroundColor: Colors.orange,
        ),
      );
      return;
    }

    if (!(_formKey.currentState?.validate() ?? false)) return;

    final phone = _phoneController.text.trim();
    final email = _emailController.text.trim();
    if (phone.isEmpty && email.isEmpty) {
      ScaffoldMessenger.of(context).showSnackBar(
        const SnackBar(
          content: Text(
            'Veuillez renseigner au moins un téléphone ou un email.',
          ),
          backgroundColor: Colors.orange,
        ),
      );
      return;
    }

    if (_selectedRooms.isEmpty) {
      ScaffoldMessenger.of(context).showSnackBar(
        const SnackBar(
          content: Text('Veuillez choisir au moins une chambre.'),
          backgroundColor: Colors.orange,
        ),
      );
      return;
    }

    setState(() => _isSaving = true);
    try {
      final response = await _apiClient.updateReservation(
        _reservation.id,
        _reservation.toUpdateJson(
          clientName: _nameController.text.trim(),
          phone: phone,
          email: email,
          checkIn: _checkIn,
          checkOut: _checkOut,
          roomIds: _selectedRooms.map((room) => _asInt(room['id'])).toList(),
          extraBeds: _extraBeds,
          extraMattresses: _extraMattresses,
          roomSegments: _roomSegmentsPayload(),
          modifiedByName: widget.userName,
          modifiedByRole: widget.role,
        ),
      );

      if (!mounted) return;
      if (response.statusCode == 200) {
        ScaffoldMessenger.of(context).showSnackBar(
          const SnackBar(
            content: Text('Réservation modifiée avec succès.'),
            backgroundColor: Colors.green,
          ),
        );
        Navigator.pop(context, true);
      } else {
        final body = json.decode(response.body);
        final message = body is Map && body['message'] != null
            ? body['message'].toString()
            : 'Erreur ${response.statusCode}';
        ScaffoldMessenger.of(context).showSnackBar(
          SnackBar(content: Text(message), backgroundColor: Colors.red),
        );
      }
    } catch (e) {
      if (mounted) {
        ScaffoldMessenger.of(context).showSnackBar(
          SnackBar(
            content: Text('Erreur réseau : $e'),
            backgroundColor: Colors.red,
          ),
        );
      }
    } finally {
      if (mounted) {
        setState(() => _isSaving = false);
      }
    }
  }

  Widget _buildSegmentCard(Map<String, dynamic> room) {
    final roomId = _asInt(room['id']);
    final draft = _segmentDrafts[roomId] ?? _defaultSegmentDraft(room);
    final title =
        'Chambre ${room['room_number']} — ${room['type']} (${room['model']})';

    Future<void> pickStart() async {
      final today = DateTime.now();
      final minDate = _isPostCheckIn && today.isAfter(_reservation.checkIn)
          ? today
          : _reservation.checkIn;
      final picked = await showDatePicker(
        context: context,
        initialDate: draft.startDate.isBefore(minDate)
            ? minDate
            : draft.startDate,
        firstDate: DateTime(minDate.year, minDate.month, minDate.day),
        lastDate: DateTime.now().add(const Duration(days: 365)),
      );
      if (picked == null) return;
      setState(() {
        draft.startDate = picked;
        if (draft.endDate.isBefore(picked)) {
          draft.endDate = picked.add(const Duration(days: 1));
        }
      });
    }

    Future<void> pickEnd() async {
      final today = DateTime.now();
      final minDate = _isPostCheckIn && today.isAfter(draft.startDate)
          ? today
          : draft.startDate.add(const Duration(days: 1));
      final picked = await showDatePicker(
        context: context,
        initialDate: draft.endDate.isBefore(minDate)
            ? minDate
            : (draft.endDate.isAfter(draft.startDate)
                  ? draft.endDate
                  : draft.startDate.add(const Duration(days: 1))),
        firstDate: DateTime(minDate.year, minDate.month, minDate.day),
        lastDate: DateTime.now().add(const Duration(days: 365)),
      );
      if (picked == null) return;
      setState(() => draft.endDate = picked);
    }

    return Card(
      margin: const EdgeInsets.only(bottom: 12),
      child: Padding(
        padding: const EdgeInsets.all(14),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Text(
              title,
              style: const TextStyle(fontWeight: FontWeight.w900, color: _ink),
            ),
            const SizedBox(height: 10),
            ListTile(
              dense: true,
              contentPadding: EdgeInsets.zero,
              leading: const Icon(Icons.login),
              title: Text('Début: ${_dateLabel(draft.startDate)}'),
              trailing: const Icon(Icons.calendar_today_outlined),
              onTap: pickStart,
            ),
            ListTile(
              dense: true,
              contentPadding: EdgeInsets.zero,
              leading: const Icon(Icons.logout),
              title: Text('Fin: ${_dateLabel(draft.endDate)}'),
              trailing: const Icon(Icons.calendar_today_outlined),
              onTap: pickEnd,
            ),
            const SizedBox(height: 6),
            _QuantitySelector(
              icon: Icons.bed_outlined,
              label: 'Lit supplémentaire',
              value: draft.extraBeds,
              maxValue: 6,
              onChanged: (value) => setState(() => draft.extraBeds = value),
            ),
            const SizedBox(height: 8),
            _QuantitySelector(
              icon: Icons.airline_seat_individual_suite_outlined,
              label: 'Matelas supplémentaire',
              value: draft.extraMattresses,
              maxValue: 6,
              onChanged: (value) =>
                  setState(() => draft.extraMattresses = value),
            ),
          ],
        ),
      ),
    );
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: AppBar(
        title: Text(
          _isPostCheckIn ? 'Modifier les options' : 'Modifier la réservation',
        ),
      ),
      body: Row(
        children: [
          Expanded(
            flex: 1,
            child: SingleChildScrollView(
              padding: const EdgeInsets.all(16),
              child: Form(
                key: _formKey,
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.stretch,
                  children: [
                    Text(
                      widget.reservation['reference']?.toString() ?? '',
                      style: const TextStyle(
                        color: _primary,
                        fontWeight: FontWeight.w900,
                      ),
                    ),
                    const SizedBox(height: 12),
                    TextFormField(
                      controller: _nameController,
                      enabled: !_isPostCheckIn,
                      decoration: const InputDecoration(
                        labelText: 'Nom du client',
                        prefixIcon: Icon(Icons.person),
                      ),
                      validator: (value) =>
                          value == null || value.trim().isEmpty
                          ? 'Nom requis'
                          : null,
                    ),
                    const SizedBox(height: 12),
                    TextFormField(
                      controller: _phoneController,
                      enabled: !_isPostCheckIn,
                      keyboardType: TextInputType.text,
                      decoration: const InputDecoration(
                        labelText: 'Téléphone',
                        prefixIcon: Icon(Icons.phone),
                      ),
                    ),
                    const SizedBox(height: 12),
                    TextFormField(
                      controller: _emailController,
                      enabled: !_isPostCheckIn,
                      keyboardType: TextInputType.emailAddress,
                      decoration: const InputDecoration(
                        labelText: 'Email / Autre contact',
                        prefixIcon: Icon(Icons.contact_mail),
                      ),
                    ),
                    const SizedBox(height: 12),
                    ListTile(
                      enabled: _canEditCheckIn,
                      contentPadding: EdgeInsets.zero,
                      leading: const Icon(Icons.login),
                      title: Text('Arrivée : $_checkInKey'),
                      trailing: const Icon(Icons.calendar_today),
                      onTap: _canEditCheckIn ? _pickCheckIn : null,
                    ),
                    ListTile(
                      enabled: true,
                      contentPadding: EdgeInsets.zero,
                      leading: const Icon(Icons.logout),
                      title: Text('Départ : $_checkOutKey'),
                      trailing: const Icon(Icons.calendar_today),
                      onTap: _pickCheckOut,
                    ),
                    if (_isPostCheckIn) ...[
                      const SizedBox(height: 8),
                      const Text(
                        'Après check-in, le départ peut être prolongé. Les nuits déjà passées restent conservées.',
                        style: TextStyle(
                          color: _muted,
                          fontWeight: FontWeight.w700,
                        ),
                      ),
                    ],
                    const SizedBox(height: 12),
                    Container(
                      width: double.infinity,
                      padding: const EdgeInsets.all(12),
                      decoration: BoxDecoration(
                        color: _primary.withValues(alpha: 0.06),
                        borderRadius: BorderRadius.circular(8),
                        border: Border.all(
                          color: _primary.withValues(alpha: 0.14),
                        ),
                      ),
                      child: const Text(
                        'Les suppléments se règlent dans chaque tranche de chambre ci-dessous.',
                        style: TextStyle(
                          color: _primaryDark,
                          fontWeight: FontWeight.w700,
                        ),
                      ),
                    ),
                    const SizedBox(height: 12),
                    Container(
                      padding: const EdgeInsets.all(12),
                      decoration: BoxDecoration(
                        color: _primary.withValues(alpha: 0.08),
                        borderRadius: BorderRadius.circular(8),
                        border: Border.all(
                          color: _primary.withValues(alpha: 0.18),
                        ),
                      ),
                      child: Text(
                        '${_selectedRooms.length} chambre(s) sélectionnée(s)',
                        style: const TextStyle(
                          color: _primaryDark,
                          fontWeight: FontWeight.w900,
                        ),
                      ),
                    ),
                    const SizedBox(height: 16),
                    Text(
                      'Découpage du séjour',
                      style: Theme.of(context).textTheme.titleMedium?.copyWith(
                        fontWeight: FontWeight.w900,
                        color: _ink,
                      ),
                    ),
                    const SizedBox(height: 8),
                    if (_selectedRooms.isEmpty)
                      const Text(
                        'Sélectionne au moins une chambre pour définir ses dates et ses options.',
                        style: TextStyle(
                          color: _muted,
                          fontWeight: FontWeight.w700,
                        ),
                      )
                    else
                      ..._selectedRooms.map(_buildSegmentCard),
                    const SizedBox(height: 20),
                    Container(
                      width: double.infinity,
                      padding: const EdgeInsets.all(12),
                      decoration: BoxDecoration(
                        color: _primary.withValues(alpha: 0.08),
                        borderRadius: BorderRadius.circular(8),
                        border: Border.all(
                          color: _primary.withValues(alpha: 0.18),
                        ),
                      ),
                      child: Column(
                        crossAxisAlignment: CrossAxisAlignment.start,
                        children: [
                          _SummaryLine(
                            label: 'Prix par nuit chambres',
                            value:
                                '${formatPrice(_calculateRoomNightPrice())} Ar',
                          ),
                          const SizedBox(height: 6),
                          _SummaryLine(
                            label: 'Prix total chambre',
                            value: '${formatPrice(_calculateRoomPrice())} Ar',
                          ),
                          const SizedBox(height: 6),
                          _SummaryLine(
                            label: 'Prix par nuit option',
                            value:
                                '${formatPrice(_calculateExtrasNightPrice())} Ar',
                          ),
                          const SizedBox(height: 6),
                          _SummaryLine(
                            label: 'Prix total option',
                            value: '${formatPrice(_calculateExtrasPrice())} Ar',
                          ),
                          const Divider(height: 20),
                          _SummaryLine(
                            label: 'Prix total',
                            value: '${formatPrice(_calculateTotalPrice())} Ar',
                            emphasized: true,
                          ),
                        ],
                      ),
                    ),
                    const SizedBox(height: 20),
                    ElevatedButton.icon(
                      onPressed: _isSaving ? null : _saveChanges,
                      icon: _isSaving
                          ? const SizedBox(
                              width: 18,
                              height: 18,
                              child: CircularProgressIndicator(strokeWidth: 2),
                            )
                          : const Icon(Icons.save_outlined),
                      label: const Text('Enregistrer les modifications'),
                    ),
                  ],
                ),
              ),
            ),
          ),
          VerticalDivider(color: Colors.grey.shade300),
          Expanded(
            flex: 2,
            child: Padding(
              padding: const EdgeInsets.all(16),
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  Row(
                    mainAxisAlignment: MainAxisAlignment.spaceBetween,
                    children: [
                      const Text(
                        'Chambres disponibles',
                        style: TextStyle(
                          fontSize: 16,
                          fontWeight: FontWeight.w900,
                          color: _ink,
                        ),
                      ),
                      IconButton(
                        onPressed: _fetchRooms,
                        icon: const Icon(Icons.refresh),
                      ),
                    ],
                  ),
                  const SizedBox(height: 12),
                  TextField(
                    onChanged: (value) =>
                        setState(() => _roomSearchQuery = value),
                    decoration: const InputDecoration(
                      labelText: 'Rechercher une chambre',
                      hintText: 'Numéro, type ou modèle',
                      prefixIcon: Icon(Icons.search),
                    ),
                  ),
                  const SizedBox(height: 10),
                  Row(
                    children: [
                      Expanded(
                        child: OutlinedButton.icon(
                          onPressed: () =>
                              setState(() => _showRoomsNeedingSplit = false),
                          icon: Icon(
                            Icons.event_available_outlined,
                            color: _showRoomsNeedingSplit
                                ? _muted
                                : _primaryDark,
                          ),
                          label: const Text('Séjour complet'),
                          style: OutlinedButton.styleFrom(
                            foregroundColor: _showRoomsNeedingSplit
                                ? _muted
                                : _primaryDark,
                            side: BorderSide(
                              color: _showRoomsNeedingSplit
                                  ? _border
                                  : _primary,
                            ),
                            backgroundColor: _showRoomsNeedingSplit
                                ? Colors.white
                                : _primary.withValues(alpha: 0.08),
                          ),
                        ),
                      ),
                      const SizedBox(width: 8),
                      Expanded(
                        child: OutlinedButton.icon(
                          onPressed: () =>
                              setState(() => _showRoomsNeedingSplit = true),
                          icon: Icon(
                            Icons.call_split_outlined,
                            color: _showRoomsNeedingSplit
                                ? _primaryDark
                                : _muted,
                          ),
                          label: const Text('Avec découpage'),
                          style: OutlinedButton.styleFrom(
                            foregroundColor: _showRoomsNeedingSplit
                                ? _primaryDark
                                : _muted,
                            side: BorderSide(
                              color: _showRoomsNeedingSplit
                                  ? _primary
                                  : _border,
                            ),
                            backgroundColor: _showRoomsNeedingSplit
                                ? _primary.withValues(alpha: 0.08)
                                : Colors.white,
                          ),
                        ),
                      ),
                    ],
                  ),
                  Padding(
                    padding: const EdgeInsets.only(top: 4),
                    child: Text(
                      _showRoomsNeedingSplit
                          ? 'Affiche aussi les chambres libres seulement une partie du séjour.'
                          : 'Affiche uniquement les chambres libres sur tout le séjour.',
                      style: const TextStyle(
                        color: _muted,
                        fontSize: 12,
                        fontWeight: FontWeight.w700,
                      ),
                    ),
                  ),
                  const SizedBox(height: 12),
                  if (_errorMessage != null)
                    Padding(
                      padding: const EdgeInsets.only(bottom: 12),
                      child: Text(
                        _errorMessage!,
                        style: const TextStyle(
                          color: _rose,
                          fontWeight: FontWeight.w800,
                        ),
                      ),
                    ),
                  if (_isLoadingRooms)
                    const Expanded(
                      child: Center(child: CircularProgressIndicator()),
                    )
                  else
                    Expanded(
                      child: ListView.builder(
                        itemCount: _filteredAvailableRooms.length,
                        itemBuilder: (context, index) {
                          final room = _filteredAvailableRooms[index];
                          final roomId = _asInt(room['id']);
                          final isSelected =
                              _initialRoomIds.contains(roomId) ||
                              _selectedRooms.any(
                                (selected) => _asInt(selected['id']) == roomId,
                              );
                          final label =
                              'Chambre ${room['room_number']} — ${room['type']} (${room['model']})';

                          return CheckboxListTile(
                            value: isSelected,
                            title: Text(
                              label,
                              style: const TextStyle(
                                fontWeight: FontWeight.w800,
                              ),
                            ),
                            subtitle: Text(
                              '${_availabilityLabel(room)}\nTarif : ${formatPrice(_getRoomPrice(room))} Ar / nuit',
                              style: const TextStyle(
                                color: _muted,
                                fontWeight: FontWeight.w700,
                              ),
                            ),
                            onChanged: (value) {
                              setState(() {
                                if (value == true) {
                                  _initialRoomIds.add(roomId);
                                  _selectedRooms.removeWhere(
                                    (selected) =>
                                        _asInt(selected['id']) == roomId,
                                  );
                                  _selectedRooms.insert(0, room);
                                  _segmentDrafts[roomId] = _defaultSegmentDraft(
                                    room,
                                  );
                                } else {
                                  _initialRoomIds.remove(roomId);
                                  _selectedRooms.removeWhere(
                                    (selected) =>
                                        _asInt(selected['id']) == roomId,
                                  );
                                  _segmentDrafts.remove(roomId);
                                }
                                _sortRoomsBySelection(_availableRooms);
                              });
                            },
                          );
                        },
                      ),
                    ),
                ],
              ),
            ),
          ),
        ],
      ),
    );
  }
}

class _ReservationsListPageState extends State<ReservationsListPage> {
  final _apiClient = const ApiClient();
  List<dynamic> _reservations = [];
  bool _isLoading = true;
  late DateTime _selectedDate;
  String _statusFilter = 'pending';
  String _bookingTypeFilter = 'all';
  String _searchQuery = '';
  final Set<int> _groupSelectionIds = {};

  @override
  void initState() {
    super.initState();
    _selectedDate = _normalizeSelectedDate(
      widget.initialDate ?? DateTime.now(),
    );
    _fetchReservations();
  }

  bool get _isAdmin => widget.role != 'receptionist';

  DateTime get _todayOnly =>
      DateTime(DateTime.now().year, DateTime.now().month, DateTime.now().day);

  DateTime get _firstSelectableDate =>
      _isAdmin ? _todayOnly.subtract(const Duration(days: 730)) : _todayOnly;

  DateTime _normalizeSelectedDate(DateTime date) {
    final normalized = DateTime(date.year, date.month, date.day);
    if (normalized.isBefore(_firstSelectableDate)) {
      return _firstSelectableDate;
    }
    return normalized;
  }

  int _asInt(dynamic value) {
    if (value is int) return value;
    if (value is num) return value.toInt();
    return int.tryParse(value?.toString() ?? '') ?? 0;
  }

  bool _isOrganizationReservation(Map<String, dynamic> reservation) {
    return (reservation['booking_type'] ?? '').toString() == 'organization';
  }

  Map<String, dynamic>? _reservationById(int id) {
    for (final item in _reservations) {
      if (item is Map && _asInt(item['id']) == id) {
        return Map<String, dynamic>.from(item);
      }
    }
    return null;
  }

  String _reservationGroupKey(Map<String, dynamic> reservation) {
    final organization = reservation['organization'];
    final organizationId = _asInt(
      organization is Map ? organization['id'] : reservation['organization_id'],
    );
    return [
      organizationId,
      (reservation['check_in'] ?? '').toString(),
      (reservation['check_out'] ?? '').toString(),
    ].join('|');
  }

  Future<void> _openGroupedFolioFromSelection() async {
    if (_groupSelectionIds.isEmpty) return;

    final selected = _groupSelectionIds
        .map(_reservationById)
        .whereType<Map<String, dynamic>>()
        .toList();
    if (selected.isEmpty) return;

    final base = selected.first;
    final baseKey = _reservationGroupKey(base);
    if (selected.any(
      (reservation) => _reservationGroupKey(reservation) != baseKey,
    )) {
      ScaffoldMessenger.of(context).showSnackBar(
        const SnackBar(
          content: Text(
            'Sélectionne seulement des réservations du même séjour.',
          ),
          backgroundColor: Colors.orange,
        ),
      );
      return;
    }

    final selectedIds = selected
        .map((reservation) => _asInt(reservation['id']))
        .toList();
    if (selectedIds.isEmpty) {
      ScaffoldMessenger.of(context).showSnackBar(
        const SnackBar(
          content: Text('Aucune réservation sélectionnée.'),
          backgroundColor: Colors.orange,
        ),
      );
      return;
    }

    final orgName = (base['organization'] is Map)
        ? ((base['organization'] as Map)['name'] ?? '').toString()
        : '';
    if (orgName.isEmpty) {
      ScaffoldMessenger.of(context).showSnackBar(
        const SnackBar(
          content: Text('Cette réservation n’est pas liée à un organisme.'),
          backgroundColor: Colors.orange,
        ),
      );
      return;
    }

    final selection = await showDialog<Map<String, dynamic>>(
      context: context,
      builder: (context) {
        String pricingMode = 'fixed';
        return AlertDialog(
          title: const Text('Facture groupée'),
          content: StatefulBuilder(
            builder: (context, setDialogState) => Column(
              mainAxisSize: MainAxisSize.min,
              children: [
                ToggleButtons(
                  isSelected: [pricingMode == 'fixed', pricingMode == 'ai'],
                  onPressed: (index) => setDialogState(() {
                    pricingMode = index == 0 ? 'fixed' : 'ai';
                  }),
                  borderRadius: BorderRadius.circular(12),
                  children: const [
                    Padding(
                      padding: EdgeInsets.symmetric(
                        horizontal: 12,
                        vertical: 10,
                      ),
                      child: Text('Tarif Fixe'),
                    ),
                    Padding(
                      padding: EdgeInsets.symmetric(
                        horizontal: 12,
                        vertical: 10,
                      ),
                      child: Text('Tarif IA'),
                    ),
                  ],
                ),
                const SizedBox(height: 8),
                Text(
                  '${selected.length} réservation(s) sélectionnée(s) pour $orgName.',
                  style: const TextStyle(color: _muted, fontSize: 12),
                ),
              ],
            ),
          ),
          actions: [
            TextButton(
              onPressed: () => Navigator.pop(context),
              child: const Text('Annuler'),
            ),
            ElevatedButton(
              onPressed: () {
                Navigator.pop(context, {'pricing_mode': pricingMode});
              },
              child: const Text('Continuer'),
            ),
          ],
        );
      },
    );

    if (selection == null || !mounted) return;

    final anchor = selected.first;
    await Navigator.push(
      context,
      MaterialPageRoute(
        builder: (_) => FolioPage(
          reservation: anchor,
          userName: widget.userName,
          role: widget.role,
          pricingMode: selection['pricing_mode']?.toString() ?? 'fixed',
          groupReservationIds: selectedIds,
        ),
      ),
    );
    _fetchReservations();
  }

  String? _organizationOccupantSummary(Map<String, dynamic> reservation) {
    if ((reservation['booking_type'] ?? '').toString() != 'organization') {
      return null;
    }

    final details = reservation['room_details'];
    if (details is! Iterable) return null;

    final occupants = <String>[];
    for (final rawRoom in details.whereType<Map>()) {
      final room = Map<String, dynamic>.from(rawRoom);
      final occupantName = (room['occupant_name'] ?? '').toString().trim();
      if (occupantName.isEmpty) continue;

      final firstName = (room['occupant_first_name'] ?? '').toString().trim();
      occupants.add([occupantName, firstName].where((value) => value.isNotEmpty).join(' '));
    }

    if (occupants.isEmpty) return null;
    return 'Occupant : ${occupants.join(' | ')}';
  }

  DateTime _reservationSortDate(Map<String, dynamic> reservation) {
    final createdAt = (reservation['created_at'] ?? '').toString();
    return DateTime.tryParse(createdAt) ??
        DateTime.fromMillisecondsSinceEpoch(0);
  }

  Future<void> _fetchReservations() async {
    if (mounted) {
      setState(() => _isLoading = true);
    }
    final dateParam = _selectedDate.toIso8601String().substring(0, 10);
    final statusParam = _statusFilter;
    final cacheKey =
        'active_reservations:${widget.role}:${widget.userName}:$dateParam:$statusParam';
    try {
      final response = await http
          .get(
            Uri.parse('$baseUrl/api/reservations/all').replace(
              queryParameters: {'date': dateParam, 'status': statusParam},
            ),
          )
          .timeout(const Duration(seconds: 5));
      if (response.statusCode == 200) {
        List<dynamic> data = json.decode(response.body);
        if (!mounted) return;
        setState(() {
          _reservations = data;
        });
        final prefs = await SharedPreferences.getInstance();
        await prefs.setString(cacheKey, json.encode(data));
      }
    } catch (e) {
      final prefs = await SharedPreferences.getInstance();
      final cached = prefs.getString(cacheKey);
      if (!mounted) return;
      if (cached != null) {
        final data = json.decode(cached) as List<dynamic>;
        setState(() {
          _reservations = data;
        });
        if (mounted) {
          ScaffoldMessenger.of(context).showSnackBar(
            const SnackBar(
              content: Text(
                'Mode dégradé: dernières réservations locales affichées.',
              ),
              backgroundColor: Colors.orange,
            ),
          );
        }
      } else {
        debugPrint("Error fetching reservations: $e");
      }
    }
    if (mounted) {
      setState(() => _isLoading = false);
    }
  }

  Future<void> _updateStatus(dynamic id, String newStatus) async {
    if (newStatus == 'annule') {
      Map<String, dynamic>? reservation;
      for (final item in _reservations) {
        if (item is Map && _asInt(item['id']) == _asInt(id)) {
          reservation = Map<String, dynamic>.from(item);
          break;
        }
      }

      if (reservation != null && !_canCancelReservation(reservation)) {
        ScaffoldMessenger.of(context).showSnackBar(
          const SnackBar(
            content: Text(
              'Après check-in ou acompte, seul un administrateur peut annuler.',
            ),
            backgroundColor: Colors.orange,
          ),
        );
        return;
      }
    }

    try {
      final response = await http.post(
        Uri.parse('$baseUrl/api/bookings/update-status'),
        headers: {'Content-Type': 'application/json'},
        body: json.encode({
          'id': id,
          'status': newStatus,
          if (newStatus == 'annule') 'cancelled_by_name': widget.userName,
          if (newStatus == 'annule') 'cancelled_by_role': widget.role,
        }),
      );
      if (!mounted) return;
      if (response.statusCode == 200) {
        if (newStatus == 'annule') {
          setState(() {
            for (final reservation in _reservations) {
              if (_asInt(reservation['id']) == _asInt(id)) {
                reservation['status'] = 'annule';
                reservation['cancelled_by_name'] = widget.userName;
                reservation['cancelled_by_role'] = widget.role;
                reservation['last_action'] = 'cancelled';
                reservation['last_action_by'] = widget.userName;
                reservation['last_action_role'] = widget.role;
              }
            }
          });
        } else {
          _fetchReservations();
        }
        ScaffoldMessenger.of(context).showSnackBar(
          const SnackBar(
            content: Text('Statut mis à jour !'),
            backgroundColor: Colors.green,
            duration: Duration(seconds: 1),
          ),
        );
      } else {
        final decoded = response.body.isNotEmpty
            ? json.decode(response.body)
            : null;
        final message = decoded is Map && decoded['message'] != null
            ? decoded['message'].toString()
            : 'Erreur ${response.statusCode}';
        ScaffoldMessenger.of(context).showSnackBar(
          SnackBar(content: Text(message), backgroundColor: Colors.red),
        );
      }
    } catch (e) {
      debugPrint("Error updating status: $e");
      if (!mounted) return;
      ScaffoldMessenger.of(context).showSnackBar(
        SnackBar(
          content: Text('Erreur réseau : $e'),
          backgroundColor: Colors.orange,
        ),
      );
    }
  }

  bool _canEditReservation(Map<String, dynamic> reservation) {
    final status = (reservation['status'] ?? '').toString();
    return status == 'en_attente' || status == 'arrive';
  }

  bool _canCancelReservation(Map<String, dynamic> reservation) {
    final status = (reservation['status'] ?? '').toString();
    final paymentStatus = (reservation['payment_status'] ?? 'unbilled')
        .toString();
    final hasAdvancePayment =
        _asInt(reservation['deposit_amount_ariary']) > 0 ||
        paymentStatus == 'partial' ||
        paymentStatus == 'paid';
    return widget.role != 'receptionist' ||
        (!hasAdvancePayment && status != 'arrive');
  }

  bool _shouldShowStatusControls(Map<String, dynamic> reservation) {
    final status = (reservation['status'] ?? '').toString();
    return status == 'en_attente' || status == 'arrive';
  }

  Future<void> _openEditReservation(Map<String, dynamic> reservation) async {
    final updated = await Navigator.push<bool>(
      context,
      _reservationRoute(
        EditReservationPage(
          reservation: reservation,
          userName: widget.userName,
          role: widget.role,
        ),
      ),
    );
    if (updated == true) {
      _fetchReservations();
    }
  }

  Future<void> _openModificationDetails(
    Map<String, dynamic> reservation,
  ) async {
    final details = reservation['last_action_details'];
    if (details is! Map || details.isEmpty) return;

    final entries = details.entries.toList()
      ..sort((a, b) => a.key.toString().compareTo(b.key.toString()));

    await showDialog<void>(
      context: context,
      builder: (context) => AlertDialog(
        title: const Text('Détails de la modification'),
        content: ConstrainedBox(
          constraints: const BoxConstraints(maxWidth: 420),
          child: SingleChildScrollView(
            child: Column(
              mainAxisSize: MainAxisSize.min,
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Text(
                  'Par ${reservation['last_action_by'] ?? 'N/A'}'
                  '${(reservation['last_action_role'] ?? '').toString().isNotEmpty ? ' / ${reservation['last_action_role']}' : ''}'
                  '${(reservation['last_action_at'] ?? '').toString().isNotEmpty ? ' • ${reservation['last_action_at']}' : ''}',
                  style: const TextStyle(
                    color: _muted,
                    fontWeight: FontWeight.w700,
                  ),
                ),
                const SizedBox(height: 12),
                ...entries.map((entry) {
                  final value = entry.value;
                  final before = value is Map ? value['before'] : null;
                  final after = value is Map ? value['after'] : null;
                  return Padding(
                    padding: const EdgeInsets.only(bottom: 10),
                    child: Column(
                      crossAxisAlignment: CrossAxisAlignment.start,
                      children: [
                        Text(
                          _auditLabel(entry.key.toString()),
                          style: const TextStyle(
                            fontWeight: FontWeight.w900,
                            color: _ink,
                          ),
                        ),
                        const SizedBox(height: 4),
                        Text('Avant : ${_auditValue(before)}'),
                        Text('Après : ${_auditValue(after)}'),
                      ],
                    ),
                  );
                }),
              ],
            ),
          ),
        ),
        actions: [
          TextButton(
            onPressed: () => Navigator.pop(context),
            child: const Text('Fermer'),
          ),
        ],
      ),
    );
  }

  String _auditLabel(String key) {
    return switch (key) {
      'check_in' => 'Arrivée',
      'check_out' => 'Départ',
      'client_name' => 'Nom du client',
      'customer_phone' => 'Téléphone',
      'customer_email' => 'Email',
      'extra_beds' => 'Lits supplémentaires',
      'extra_mattresses' => 'Matelas supplémentaires',
      'room_ids' => 'Chambres',
      _ => key.replaceAll('_', ' '),
    };
  }

  String _auditValue(dynamic value) {
    if (value is List) {
      return value.map((item) => item.toString()).join(', ');
    }
    if (value == null || value.toString().isEmpty) return 'N/A';
    return value.toString();
  }

  Future<bool> _submitDeposit({
    required Map<String, dynamic> reservation,
    required int amount,
    required String paymentMethod,
    required String? paymentOperator,
    required String reference,
  }) async {
    try {
      final response = await _apiClient.postJson(
        '/api/reservations/${reservation['id']}/deposit',
        {
          'amount_ariary': amount,
          'payment_method': paymentMethod,
          'payment_operator': paymentOperator,
          'reference': reference,
          'processed_by_name': widget.userName,
          'processed_by_role': widget.role,
        },
        const Duration(seconds: 45),
      );

      if (!mounted) return false;

      final decoded = response.body.isNotEmpty
          ? json.decode(response.body)
          : null;
      if (response.statusCode >= 200 && response.statusCode < 300) {
        if (decoded is Map) {
          final invoice = decoded['invoice'] is Map
              ? Map<String, dynamic>.from(decoded['invoice'] as Map)
              : null;
          final payment = decoded['payment'] is Map
              ? Map<String, dynamic>.from(decoded['payment'] as Map)
              : null;
          if (invoice != null || payment != null) {
            setState(() {
              final reservationId = _asInt(reservation['id']);
              final index = _reservations.indexWhere(
                (item) => _asInt((item as Map)['id']) == reservationId,
              );
              if (index != -1) {
                final updated = Map<String, dynamic>.from(
                  _reservations[index] as Map,
                );
                if (invoice != null) {
                  updated['deposit_amount_ariary'] =
                      invoice['deposit_amount_ariary'] ??
                      updated['deposit_amount_ariary'];
                  updated['paid_amount_ariary'] =
                      invoice['paid_amount_ariary'] ??
                      updated['paid_amount_ariary'];
                  updated['balance_amount_ariary'] =
                      invoice['balance_amount_ariary'] ??
                      updated['balance_amount_ariary'];
                  updated['payment_status'] =
                      invoice['status'] ?? updated['payment_status'];
                }
                if (payment != null) {
                  updated['latest_deposit_method'] = payment['payment_method'];
                  updated['latest_deposit_operator'] =
                      payment['payment_operator'];
                  updated['latest_deposit_processed_by'] =
                      payment['processed_by_name'];
                  updated['latest_deposit_processed_by_role'] =
                      payment['processed_by_role'];
                }
                _reservations[index] = updated;
              }
            });
          }
        }
        ScaffoldMessenger.of(context).showSnackBar(
          const SnackBar(
            content: Text('Acompte enregistré.'),
            backgroundColor: Colors.green,
          ),
        );
        return true;
      }

      final message = decoded is Map && decoded['message'] != null
          ? decoded['message'].toString()
          : 'Erreur ${response.statusCode}';
      if (response.statusCode == 429) {
        ScaffoldMessenger.of(context).showSnackBar(
          const SnackBar(
            content: Text(
              'Le serveur traite encore une demande précédente. Attends quelques secondes puis réessaie.',
            ),
            backgroundColor: Colors.orange,
          ),
        );
        return false;
      }
      ScaffoldMessenger.of(context).showSnackBar(
        SnackBar(content: Text(message), backgroundColor: Colors.red),
      );
    } catch (e) {
      if (!mounted) return false;
      ScaffoldMessenger.of(context).showSnackBar(
        SnackBar(
          content: Text('Erreur réseau : $e'),
          backgroundColor: Colors.orange,
        ),
      );
      return false;
    }

    return false;
  }

  Future<void> _openDepositDialog(Map<String, dynamic> reservation) async {
    await showDialog<void>(
      context: context,
      builder: (context) {
        final amountController = TextEditingController(text: '');
        final referenceController = TextEditingController();
        String paymentMethod = 'Espèces';
        String paymentOperator = 'mvola';
        bool isSubmitting = false;
        const methods = [
          'Espèces',
          'Carte Bancaire',
          'Mobile Money',
          'Chèque',
          'Virement',
        ];
        const operators = ['mvola', 'orange money', 'airtel money'];

        return StatefulBuilder(
          builder: (context, setDialogState) {
            return AlertDialog(
              title: const Text('Enregistrer un acompte'),
              content: SingleChildScrollView(
                child: Column(
                  mainAxisSize: MainAxisSize.min,
                  children: [
                    TextField(
                      controller: amountController,
                      keyboardType: TextInputType.number,
                      inputFormatters: const [AriaryInputFormatter()],
                      decoration: const InputDecoration(
                        labelText: 'Montant de l’acompte (Ar)',
                        prefixIcon: Icon(Icons.payments_outlined),
                      ),
                    ),
                    const SizedBox(height: 12),
                    DropdownButtonFormField<String>(
                      initialValue: paymentMethod,
                      decoration: const InputDecoration(
                        labelText: 'Méthode de paiement',
                        prefixIcon: Icon(Icons.credit_card),
                      ),
                      items: methods
                          .map(
                            (value) => DropdownMenuItem(
                              value: value,
                              child: Text(value),
                            ),
                          )
                          .toList(),
                      onChanged: (value) => setDialogState(() {
                        paymentMethod = value ?? paymentMethod;
                        if (paymentMethod != 'Mobile Money') {
                          paymentOperator = 'mvola';
                        }
                      }),
                    ),
                    if (paymentMethod == 'Mobile Money') ...[
                      const SizedBox(height: 12),
                      DropdownButtonFormField<String>(
                        initialValue: paymentOperator,
                        decoration: const InputDecoration(
                          labelText: 'Opérateur',
                          prefixIcon: Icon(Icons.phone_android),
                        ),
                        items: operators
                            .map(
                              (value) => DropdownMenuItem(
                                value: value,
                                child: Text(value),
                              ),
                            )
                            .toList(),
                        onChanged: (value) => setDialogState(
                          () => paymentOperator = value ?? paymentOperator,
                        ),
                      ),
                    ],
                    const SizedBox(height: 12),
                    TextField(
                      controller: referenceController,
                      decoration: const InputDecoration(
                        labelText: 'Référence (optionnel)',
                        prefixIcon: Icon(Icons.tag),
                      ),
                    ),
                  ],
                ),
              ),
              actions: [
                TextButton(
                  onPressed: isSubmitting ? null : () => Navigator.pop(context),
                  child: const Text('Annuler'),
                ),
                ElevatedButton(
                  onPressed: isSubmitting
                      ? null
                      : () async {
                          final amount = parseAriaryAmount(
                            amountController.text,
                          );
                          if (amount <= 0) {
                            ScaffoldMessenger.of(context).showSnackBar(
                              const SnackBar(
                                content: Text(
                                  'Saisis un montant d’acompte valide.',
                                ),
                                backgroundColor: Colors.orange,
                              ),
                            );
                            return;
                          }

                          setDialogState(() => isSubmitting = true);
                          try {
                            final ok = await _submitDeposit(
                              reservation: reservation,
                              amount: amount,
                              paymentMethod: paymentMethod,
                              paymentOperator: paymentMethod == 'Mobile Money'
                                  ? paymentOperator
                                  : null,
                              reference: referenceController.text.trim(),
                            );
                            if (!context.mounted || !ok) return;
                            Navigator.pop(context);
                          } finally {
                            if (context.mounted) {
                              setDialogState(() => isSubmitting = false);
                            }
                          }
                        },
                  child: isSubmitting
                      ? const SizedBox(
                          width: 18,
                          height: 18,
                          child: CircularProgressIndicator(strokeWidth: 2),
                        )
                      : const Text('Valider'),
                ),
              ],
            );
          },
        );
      },
    );
  }

  Future<void> _openProformaFolio(Map<String, dynamic> reservation) async {
    await Navigator.push(
      context,
      MaterialPageRoute(
        builder: (_) => FolioPage(
          reservation: reservation,
          userName: widget.userName,
          role: widget.role,
          pricingMode: 'fixed',
          initialDocumentType: 'proforma',
          proformaOnly: true,
        ),
      ),
    );
    _fetchReservations();
  }

  Future<void> _openFolio(Map<String, dynamic> reservation) async {
    final isWaiting = (reservation['status'] ?? '').toString() == 'en_attente';
    if (isWaiting) {
      if (widget.role == 'admin' || widget.role == 'superadmin') {
        await _openDepositDialog(reservation);
      } else {
        await _openProformaFolio(reservation);
      }
      return;
    }

    final selection = await showDialog<Map<String, dynamic>>(
      context: context,
      builder: (context) {
        String pricingMode = 'fixed';
        return AlertDialog(
          title: const Text('Tarif avant facturation'),
          content: StatefulBuilder(
            builder: (context, setDialogState) => Column(
              mainAxisSize: MainAxisSize.min,
              children: [
                ToggleButtons(
                  isSelected: [pricingMode == 'fixed', pricingMode == 'ai'],
                  onPressed: (index) => setDialogState(() {
                    pricingMode = index == 0 ? 'fixed' : 'ai';
                  }),
                  borderRadius: BorderRadius.circular(12),
                  children: const [
                    Padding(
                      padding: EdgeInsets.symmetric(
                        horizontal: 12,
                        vertical: 10,
                      ),
                      child: Text('Tarif Fixe'),
                    ),
                    Padding(
                      padding: EdgeInsets.symmetric(
                        horizontal: 12,
                        vertical: 10,
                      ),
                      child: Text('Tarif IA'),
                    ),
                  ],
                ),
                const SizedBox(height: 8),
                const Text(
                  'Le tarif fixe reste la sélection par défaut.',
                  style: TextStyle(color: _muted, fontSize: 12),
                ),
              ],
            ),
          ),
          actions: [
            TextButton(
              onPressed: () => Navigator.pop(context),
              child: const Text('Annuler'),
            ),
            ElevatedButton(
              onPressed: () {
                Navigator.pop(context, {'pricing_mode': pricingMode});
              },
              child: const Text('Continuer'),
            ),
          ],
        );
      },
    );

    if (selection == null) return;
    if (!mounted) return;

    await Navigator.push(
      context,
      MaterialPageRoute(
        builder: (_) => FolioPage(
          reservation: reservation,
          userName: widget.userName,
          role: widget.role,
          pricingMode: selection['pricing_mode']?.toString() ?? 'fixed',
        ),
      ),
    );
    _fetchReservations();
  }

  @override
  Widget build(BuildContext context) {
    final query = _searchQuery.trim().toLowerCase();
    final selectedDateKey = _selectedDate.toIso8601String().substring(0, 10);
    final displayedReservations =
        _reservations.where((reservation) {
          final clientName = (reservation['client_name'] ?? '')
              .toString()
              .toLowerCase();
          final matchesSearch = query.isEmpty || clientName.contains(query);
          final status = (reservation['status'] ?? '').toString();
          final paymentStatus = (reservation['payment_status'] ?? 'unbilled')
              .toString();
          final bookingType = (reservation['booking_type'] ?? '').toString();
          final matchesStatus = switch (_statusFilter) {
            'pending' => status == 'en_attente',
            'unpaid' =>
              status == 'arrive' &&
                  (paymentStatus == 'unpaid' ||
                      paymentStatus == 'partial' ||
                      paymentStatus == 'unbilled'),
            'paid' => paymentStatus == 'paid',
            _ => true,
          };
          final matchesType = switch (_bookingTypeFilter) {
            'organization' => bookingType == 'organization',
            'individual' => bookingType != 'organization',
            _ => true,
          };
          return matchesSearch && matchesStatus && matchesType;
        }).toList()..sort((a, b) {
          final checkInCompare = (a['check_in'] ?? '').toString().compareTo(
            (b['check_in'] ?? '').toString(),
          );
          if (checkInCompare != 0) return checkInCompare;

          final createdCompare = _reservationSortDate(
            a,
          ).compareTo(_reservationSortDate(b));
          if (createdCompare != 0) return createdCompare;

          final typeCompare = (a['booking_type'] ?? '').toString().compareTo(
            (b['booking_type'] ?? '').toString(),
          );
          if (typeCompare != 0) return typeCompare;

          final nameCompare = (a['client_name'] ?? '')
              .toString()
              .toLowerCase()
              .compareTo((b['client_name'] ?? '').toString().toLowerCase());
          if (nameCompare != 0) return nameCompare;

          return _asInt(a['id']).compareTo(_asInt(b['id']));
        });

    return Scaffold(
      appBar: AppBar(
        title: const Text('Réservations'),
        actions: [
          IconButton(
            onPressed: () {
              setState(() {
                _statusFilter = 'pending';
              });
              _fetchReservations();
            },
            icon: const Icon(Icons.refresh),
          ),
          if (_groupSelectionIds.isNotEmpty)
            TextButton.icon(
              onPressed: _openGroupedFolioFromSelection,
              icon: const Icon(Icons.groups_outlined, color: _primaryDark),
              label: Text(
                'Grouper (${_groupSelectionIds.length})',
                style: const TextStyle(
                  color: _primaryDark,
                  fontWeight: FontWeight.w800,
                ),
              ),
            ),
          if (_groupSelectionIds.isNotEmpty)
            IconButton(
              tooltip: 'Effacer la sélection',
              onPressed: () {
                setState(() => _groupSelectionIds.clear());
              },
              icon: const Icon(Icons.clear_all_outlined),
            ),
          TextButton(
            onPressed: () {
              setState(() {
                _statusFilter = _statusFilter == 'all' ? 'pending' : 'all';
              });
              _fetchReservations();
            },
            child: Text(
              _statusFilter == 'all' ? 'En attente' : 'Voir Tout',
              style: const TextStyle(
                color: _primaryDark,
                fontWeight: FontWeight.w800,
              ),
            ),
          ),
        ],
      ),
      body: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          Padding(
            padding: const EdgeInsets.fromLTRB(16, 12, 16, 0),
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.stretch,
              children: [
                _ReservationSearchBar(
                  initialValue: _searchQuery,
                  onChanged: (value) => setState(() => _searchQuery = value),
                ),
                const SizedBox(height: 12),
                _ReservationStatusSelector(
                  value: _statusFilter,
                  onChanged: (value) {
                    setState(() {
                      _statusFilter = value;
                    });
                    _fetchReservations();
                  },
                ),
                const SizedBox(height: 12),
                Wrap(
                  spacing: 8,
                  runSpacing: 8,
                  children: [
                    ChoiceChip(
                      label: const Text('Tous'),
                      selected: _bookingTypeFilter == 'all',
                      onSelected: (_) {
                        setState(() => _bookingTypeFilter = 'all');
                      },
                    ),
                    ChoiceChip(
                      label: const Text('Organisme'),
                      selected: _bookingTypeFilter == 'organization',
                      onSelected: (_) {
                        setState(() => _bookingTypeFilter = 'organization');
                      },
                    ),
                    ChoiceChip(
                      label: const Text('Particulier'),
                      selected: _bookingTypeFilter == 'individual',
                      onSelected: (_) {
                        setState(() => _bookingTypeFilter = 'individual');
                      },
                    ),
                  ],
                ),
                const SizedBox(height: 12),
                Wrap(
                  spacing: 12,
                  runSpacing: 8,
                  alignment: WrapAlignment.spaceBetween,
                  crossAxisAlignment: WrapCrossAlignment.center,
                  children: [
                    OutlinedButton.icon(
                      onPressed: () async {
                        var d = await showDatePicker(
                          context: context,
                          initialDate: _selectedDate,
                          firstDate: _firstSelectableDate,
                          lastDate: DateTime.now().add(
                            const Duration(days: 365),
                          ),
                        );
                        if (d != null) {
                          setState(
                            () => _selectedDate = _normalizeSelectedDate(d),
                          );
                          _fetchReservations();
                        }
                      },
                      icon: const Icon(Icons.calendar_today_outlined, size: 18),
                      label: Text(selectedDateKey),
                      style: OutlinedButton.styleFrom(
                        foregroundColor: _ink,
                        backgroundColor: Colors.white,
                        side: const BorderSide(color: _border),
                        shape: RoundedRectangleBorder(
                          borderRadius: BorderRadius.circular(12),
                        ),
                      ),
                    ),
                  ],
                ),
              ],
            ),
          ),
          const SizedBox(height: 8),
          Expanded(
            child: _isLoading
                ? const Center(child: CircularProgressIndicator())
                : displayedReservations.isEmpty
                ? const Center(child: Text("Aucune réservation trouvée."))
                : ListView.builder(
                    itemCount: displayedReservations.length,
                    itemBuilder: (context, index) {
                      final res = Map<String, dynamic>.from(
                        displayedReservations[index] as Map,
                      );
                      final canEdit = _canEditReservation(res);
                      final status = (res['status'] ?? '').toString();
                      final isPostCheckIn =
                          status == 'arrive' || status == 'check_out_manuel';
                      final showStatusControls = _shouldShowStatusControls(res);
                      final isOrganization = _isOrganizationReservation(res);
                      final isGroupSelected = _groupSelectionIds.contains(
                        _asInt(res['id']),
                      );
                      final paymentStatus =
                          (res['payment_status'] ?? 'unbilled').toString();
                      final invoiceStatus = (res['invoice_status'] ?? '')
                          .toString()
                          .trim();
                      final invoiceNumber = (res['invoice_number'] ?? '')
                          .toString()
                          .trim();
                      final hasInvoice =
                          invoiceStatus.isNotEmpty && invoiceStatus != 'none' ||
                          invoiceNumber.isNotEmpty && invoiceNumber != 'N/A';
                      final occupantSummary = _organizationOccupantSummary(res);
                      return Card(
                        margin: const EdgeInsets.symmetric(
                          horizontal: 16,
                          vertical: 6,
                        ),
                        child: Padding(
                          padding: const EdgeInsets.all(14),
                          child: Column(
                            crossAxisAlignment: CrossAxisAlignment.start,
                            children: [
                              Row(
                                children: [
                                  Expanded(
                                    child: Text(
                                      res['client_name'] ?? 'Client',
                                      style: const TextStyle(
                                        color: _ink,
                                        fontWeight: FontWeight.w900,
                                        fontSize: 16,
                                      ),
                                    ),
                                  ),
                                  if (status != 'annule')
                                    IconButton(
                                      tooltip: isPostCheckIn
                                          ? 'Facture'
                                          : 'Acompte',
                                      onPressed: () => _openFolio(res),
                                      icon: Icon(
                                        isPostCheckIn
                                            ? Icons.receipt_long_outlined
                                            : Icons.savings_outlined,
                                      ),
                                      color: _primaryDark,
                                    ),
                                  if (isOrganization)
                                    Checkbox(
                                      value: isGroupSelected,
                                      onChanged: (value) {
                                        setState(() {
                                          final id = _asInt(res['id']);
                                          if (value == true) {
                                            _groupSelectionIds.add(id);
                                          } else {
                                            _groupSelectionIds.remove(id);
                                          }
                                        });
                                      },
                                      visualDensity: VisualDensity.compact,
                                    ),
                                  if (status == 'en_attente')
                                    IconButton(
                                      tooltip: 'Facture proforma',
                                      onPressed: () => _openProformaFolio(res),
                                      icon: const Icon(
                                        Icons.request_quote_outlined,
                                      ),
                                      color: _primaryDark,
                                    ),
                                  if (canEdit)
                                    IconButton(
                                      tooltip: isPostCheckIn
                                          ? 'Changer chambre/options'
                                          : 'Modifier la réservation',
                                      onPressed: () =>
                                          _openEditReservation(res),
                                      icon: Icon(
                                        isPostCheckIn
                                            ? Icons.swap_horiz_outlined
                                            : Icons.edit_calendar_outlined,
                                      ),
                                      color: _primaryDark,
                                    ),
                                  _StatusChip(status: res['status']),
                                ],
                              ),
                              const SizedBox(height: 4),
                              Text(
                                res['reference'] ?? '',
                                style: const TextStyle(
                                  color: _primary,
                                  fontWeight: FontWeight.w800,
                                  fontSize: 12,
                                ),
                              ),
                              const SizedBox(height: 4),
                              Wrap(
                                spacing: 8,
                                runSpacing: 6,
                                children: [
                                  if ((res['receptionist'] ?? '')
                                          .toString()
                                          .isNotEmpty &&
                                      (res['receptionist'] ?? '').toString() !=
                                          'N/A')
                                    _MiniInfoChip(
                                      label: 'Pris par ${res['receptionist']}',
                                    ),
                                  if ((res['last_action'] ?? '').toString() ==
                                          'modified' &&
                                      (res['last_action_by'] ?? '')
                                          .toString()
                                          .isNotEmpty)
                                    InkWell(
                                      onTap: () =>
                                          _openModificationDetails(res),
                                      borderRadius: BorderRadius.circular(999),
                                      child: _MiniInfoChip(
                                        label:
                                            'Modifié par ${res['last_action_by']}',
                                        accent: true,
                                      ),
                                    ),
                                  if ((res['last_action'] ?? '').toString() ==
                                          'check_in' &&
                                      (res['last_action_by'] ?? '')
                                          .toString()
                                          .isNotEmpty)
                                    _MiniInfoChip(
                                      label:
                                          'Check-in par ${res['last_action_by']}',
                                    ),
                                ],
                              ),
                              if (_asInt(res['deposit_amount_ariary']) > 0)
                                Padding(
                                  padding: const EdgeInsets.only(top: 4),
                                  child: Text(
                                    [
                                          'Acompte : ${formatPrice(_asInt(res['deposit_amount_ariary']))} Ar',
                                          [
                                                res['latest_deposit_method']
                                                    ?.toString(),
                                                (res['latest_deposit_method'] ??
                                                                '')
                                                            .toString() ==
                                                        'Mobile Money'
                                                    ? res['latest_deposit_operator']
                                                          ?.toString()
                                                    : null,
                                              ]
                                              .where(
                                                (value) =>
                                                    value != null &&
                                                    value.isNotEmpty,
                                              )
                                              .join(' / '),
                                          [
                                                res['latest_deposit_processed_by']
                                                    ?.toString(),
                                                res['latest_deposit_processed_by_role']
                                                    ?.toString(),
                                              ]
                                              .where(
                                                (value) =>
                                                    value != null &&
                                                    value.isNotEmpty,
                                              )
                                              .join(' / '),
                                        ]
                                        .where(
                                          (value) =>
                                              value.toString().isNotEmpty,
                                        )
                                        .join(' • '),
                                    style: const TextStyle(
                                      color: _primaryDark,
                                      fontWeight: FontWeight.w800,
                                      fontSize: 12,
                                    ),
                                  ),
                                ),
                              const SizedBox(height: 10),
                              Text(
                                (res['booking_type'] ?? '').toString() ==
                                        'organization'
                                    ? [
                                        if ((res['organization_phone'] ?? '')
                                            .toString()
                                            .isNotEmpty)
                                          'Siège : ${res['organization_phone']}',
                                        if ((res['phone'] ?? '')
                                            .toString()
                                            .isNotEmpty)
                                          'Numéro contact : ${res['phone']}',
                                        if ((res['email'] ?? '')
                                                .toString()
                                                .isNotEmpty &&
                                            res['email'].toString() != 'N/A')
                                          'Email contact : ${res['email']}',
                                      ].join(' | ')
                                    : (res['email'] == 'N/A' ||
                                          res['email'] == null ||
                                          res['email'].toString().isEmpty)
                                    ? 'Contact : ${res['phone']}'
                                    : 'Tél. ${res['phone']} | ${res['email']}',
                                style: const TextStyle(
                                  color: _muted,
                                  fontSize: 12,
                                ),
                              ),
                              const SizedBox(height: 4),
                              Text(
                                'N° chambres : ${res['room_numbers'] ?? 'N/A'}',
                                style: const TextStyle(
                                  color: _ink,
                                  fontWeight: FontWeight.w900,
                                ),
                              ),
                              Text('Chambres : ${res['rooms']}'),
                              if (occupantSummary != null)
                                Text(
                                  occupantSummary,
                                  style: const TextStyle(
                                    color: _muted,
                                    fontSize: 12,
                                    fontWeight: FontWeight.w600,
                                  ),
                                ),
                              if (_asInt(res['extra_beds']) > 0 ||
                                  _asInt(res['extra_mattresses']) > 0)
                                Padding(
                                  padding: const EdgeInsets.only(top: 2),
                                  child: Text(
                                    'Suppléments : ${_asInt(res['extra_beds'])} lit(s), ${_asInt(res['extra_mattresses'])} matelas',
                                  ),
                                ),
                              Text(
                                'Séjour : ${res['check_in']} au ${res['check_out']}',
                              ),
                              const SizedBox(height: 4),
                              _ReservationPaymentBadge(
                                paymentStatus: paymentStatus,
                                hasInvoice: hasInvoice,
                              ),
                              const SizedBox(height: 10),
                              Row(
                                children: [
                                  Expanded(
                                    child: Container(
                                      padding: const EdgeInsets.symmetric(
                                        horizontal: 10,
                                        vertical: 8,
                                      ),
                                      decoration: BoxDecoration(
                                        color: _primary.withValues(alpha: 0.08),
                                        borderRadius: BorderRadius.circular(8),
                                      ),
                                      child: Column(
                                        crossAxisAlignment:
                                            CrossAxisAlignment.start,
                                        children: [
                                          Text(
                                            'Prix fixe : ${formatPrice(res['fixed_total_price'] ?? res['total_price'])} Ar',
                                            style: const TextStyle(
                                              color: _primaryDark,
                                              fontWeight: FontWeight.w900,
                                              fontSize: 12,
                                            ),
                                          ),
                                          if ((res['booking_type'] ?? '')
                                                  .toString() !=
                                              'organization')
                                            Text(
                                              'Prix ajusté (IA) : ${formatPrice(res['total_price'])} Ar',
                                              style: const TextStyle(
                                                color: _muted,
                                                fontStyle: FontStyle.italic,
                                                fontWeight: FontWeight.w600,
                                                fontSize: 12,
                                              ),
                                            ),
                                        ],
                                      ),
                                    ),
                                  ),
                                  if (showStatusControls) ...[
                                    const SizedBox(width: 10),
                                    _ReservationStatusPills(
                                      status: status,
                                      showCancel: _canCancelReservation(res),
                                      showProgressActions: !isPostCheckIn,
                                      onChanged: (val) async {
                                        if (val == 'arrive') {
                                          final result =
                                              await Navigator.push<bool>(
                                                context,
                                                MaterialPageRoute(
                                                  builder: (_) => CheckInPage(
                                                    reservation:
                                                        Reservation.fromJson(
                                                          Map<
                                                            String,
                                                            dynamic
                                                          >.from(res),
                                                        ),
                                                    userName: widget.userName,
                                                    role: widget.role,
                                                  ),
                                                ),
                                              );
                                          if (result == true) {
                                            _fetchReservations();
                                          }
                                        } else if (val == 'annule') {
                                          setState(() {
                                            res['status'] = val;
                                          });
                                          _updateStatus(res['id'], val);
                                        } else {
                                          setState(() {
                                            res['status'] = val;
                                          });
                                          _updateStatus(res['id'], val);
                                        }
                                      },
                                    ),
                                  ],
                                ],
                              ),
                              const SizedBox(height: 8),
                            ],
                          ),
                        ),
                      );
                    },
                  ),
          ),
        ],
      ),
    );
  }
}

class _StatusChip extends StatelessWidget {
  const _StatusChip({required this.status});

  final dynamic status;

  @override
  Widget build(BuildContext context) {
    final value = status?.toString() ?? '';
    final label = switch (value) {
      'arrive' => 'Check-in',
      'check_out_manuel' => 'Check-out manuel',
      'annule' => 'Annulé',
      _ => 'En attente',
    };
    final color = switch (value) {
      'arrive' => const Color(0xFF047857),
      'check_out_manuel' => const Color(0xFFD97706),
      'annule' => const Color(0xFFBE123C),
      _ => const Color(0xFF0369A1),
    };

    return Container(
      padding: const EdgeInsets.symmetric(horizontal: 8, vertical: 5),
      decoration: BoxDecoration(
        color: color.withValues(alpha: 0.10),
        borderRadius: BorderRadius.circular(6),
      ),
      child: Text(
        label,
        style: TextStyle(
          color: color,
          fontSize: 11,
          fontWeight: FontWeight.w900,
        ),
      ),
    );
  }
}

class _MiniInfoChip extends StatelessWidget {
  const _MiniInfoChip({required this.label, this.accent = false});

  final String label;
  final bool accent;

  @override
  Widget build(BuildContext context) {
    return Container(
      padding: const EdgeInsets.symmetric(horizontal: 8, vertical: 5),
      decoration: BoxDecoration(
        color: accent
            ? _primary.withValues(alpha: 0.12)
            : _border.withValues(alpha: 0.55),
        borderRadius: BorderRadius.circular(999),
        border: Border.all(
          color: accent ? _primary.withValues(alpha: 0.22) : _border,
        ),
      ),
      child: Text(
        label,
        style: TextStyle(
          color: accent ? _primaryDark : _muted,
          fontSize: 11,
          fontWeight: FontWeight.w800,
        ),
      ),
    );
  }
}

class _ReservationStatusPills extends StatelessWidget {
  const _ReservationStatusPills({
    required this.status,
    required this.showCancel,
    required this.showProgressActions,
    required this.onChanged,
  });

  final String status;
  final bool showCancel;
  final bool showProgressActions;
  final ValueChanged<String> onChanged;

  @override
  Widget build(BuildContext context) {
    String normalized = status;
    if (normalized != 'en_attente' &&
        normalized != 'arrive' &&
        normalized != 'check_out_manuel' &&
        normalized != 'annule') {
      normalized = 'en_attente';
    }

    return Wrap(
      spacing: 6,
      runSpacing: 6,
      alignment: WrapAlignment.end,
      children: [
        if (showProgressActions) ...[
          _StatusChoiceChip(
            label: 'En attente',
            selected: normalized == 'en_attente',
            onSelected: () => onChanged('en_attente'),
          ),
          _StatusChoiceChip(
            label: 'Check-in',
            selected: normalized == 'arrive',
            onSelected: () => onChanged('arrive'),
          ),
        ],
        if (showCancel)
          _StatusChoiceChip(
            label: normalized == 'arrive' ? 'Annuler' : 'Annulé',
            selected: normalized == 'annule',
            onSelected: () => onChanged('annule'),
          ),
      ],
    );
  }
}

class _QuantitySelector extends StatelessWidget {
  const _QuantitySelector({
    required this.icon,
    required this.label,
    required this.value,
    required this.onChanged,
    this.maxValue,
  });

  final IconData icon;
  final String label;
  final int value;
  final ValueChanged<int> onChanged;
  final int? maxValue;

  @override
  Widget build(BuildContext context) {
    return Container(
      padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 10),
      decoration: BoxDecoration(
        border: Border.all(color: _border),
        borderRadius: BorderRadius.circular(8),
      ),
      child: Row(
        children: [
          Icon(icon, color: _primaryDark),
          const SizedBox(width: 10),
          Expanded(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Text(
                  label,
                  style: const TextStyle(fontWeight: FontWeight.w800),
                ),
                if (maxValue != null)
                  Text(
                    'Restant : $maxValue',
                    style: const TextStyle(color: _muted, fontSize: 12),
                  ),
              ],
            ),
          ),
          IconButton(
            onPressed: value <= 0 ? null : () => onChanged(value - 1),
            icon: const Icon(Icons.remove_circle_outline),
          ),
          SizedBox(
            width: 32,
            child: Text(
              value.toString(),
              textAlign: TextAlign.center,
              style: const TextStyle(fontWeight: FontWeight.w900),
            ),
          ),
          IconButton(
            onPressed: maxValue != null && value >= maxValue!
                ? null
                : () => onChanged(value + 1),
            icon: const Icon(Icons.add_circle_outline),
          ),
        ],
      ),
    );
  }
}

class _ReservationSearchBar extends StatefulWidget {
  const _ReservationSearchBar({
    required this.initialValue,
    required this.onChanged,
  });

  final String initialValue;
  final ValueChanged<String> onChanged;

  @override
  State<_ReservationSearchBar> createState() => _ReservationSearchBarState();
}

class _ReservationSearchBarState extends State<_ReservationSearchBar> {
  late final TextEditingController _controller;

  @override
  void initState() {
    super.initState();
    _controller = TextEditingController(text: widget.initialValue);
  }

  @override
  void didUpdateWidget(covariant _ReservationSearchBar oldWidget) {
    super.didUpdateWidget(oldWidget);
    if (oldWidget.initialValue != widget.initialValue &&
        widget.initialValue != _controller.text) {
      _controller.text = widget.initialValue;
    }
  }

  @override
  void dispose() {
    _controller.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    return TextField(
      controller: _controller,
      onChanged: widget.onChanged,
      decoration: const InputDecoration(
        hintText: 'Rechercher un client',
        prefixIcon: Icon(Icons.search),
      ),
    );
  }
}

class _ReservationStatusSelector extends StatelessWidget {
  const _ReservationStatusSelector({
    required this.value,
    required this.onChanged,
  });

  final String value;
  final ValueChanged<String> onChanged;

  @override
  Widget build(BuildContext context) {
    return Wrap(
      spacing: 8,
      runSpacing: 8,
      children: [
        _StatusChoiceChip(
          label: 'Tout',
          selected: value == 'all',
          onSelected: () => onChanged('all'),
        ),
        _StatusChoiceChip(
          label: 'En attente',
          selected: value == 'pending',
          onSelected: () => onChanged('pending'),
        ),
        _StatusChoiceChip(
          label: 'Non payées',
          selected: value == 'unpaid',
          onSelected: () => onChanged('unpaid'),
        ),
        _StatusChoiceChip(
          label: 'Payées',
          selected: value == 'paid',
          onSelected: () => onChanged('paid'),
        ),
      ],
    );
  }
}

class _SummaryLine extends StatelessWidget {
  const _SummaryLine({
    required this.label,
    required this.value,
    this.emphasized = false,
  });

  final String label;
  final String value;
  final bool emphasized;

  @override
  Widget build(BuildContext context) {
    final labelStyle = TextStyle(
      color: emphasized ? _primaryDark : _muted,
      fontWeight: FontWeight.w800,
      fontSize: emphasized ? 15 : 13,
    );
    final valueStyle = TextStyle(
      color: _primaryDark,
      fontWeight: FontWeight.w900,
      fontSize: emphasized ? 18 : 14,
    );

    return Row(
      mainAxisAlignment: MainAxisAlignment.spaceBetween,
      children: [
        Text(label, style: labelStyle),
        Text(value, style: valueStyle),
      ],
    );
  }
}

class _RoomSegmentDraft {
  _RoomSegmentDraft({
    required this.roomId,
    required this.roomLabel,
    required this.startDate,
    required this.endDate,
    required this.extraBeds,
    required this.extraMattresses,
  });

  final int roomId;
  final String roomLabel;
  DateTime startDate;
  DateTime endDate;
  int extraBeds;
  int extraMattresses;
}

class _StatusChoiceChip extends StatelessWidget {
  const _StatusChoiceChip({
    required this.label,
    required this.selected,
    required this.onSelected,
  });

  final String label;
  final bool selected;
  final VoidCallback onSelected;

  @override
  Widget build(BuildContext context) {
    return ChoiceChip(
      label: Text(label),
      selected: selected,
      onSelected: (_) => onSelected(),
      labelStyle: TextStyle(
        color: selected ? _primary : _muted,
        fontWeight: FontWeight.w800,
      ),
      selectedColor: _primary.withValues(alpha: 0.12),
      backgroundColor: Colors.white,
      side: BorderSide(color: selected ? _primary : _border),
      shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(10)),
      showCheckmark: false,
    );
  }
}

class _ReservationPaymentBadge extends StatelessWidget {
  const _ReservationPaymentBadge({
    required this.paymentStatus,
    required this.hasInvoice,
  });

  final String paymentStatus;
  final bool hasInvoice;

  @override
  Widget build(BuildContext context) {
    final label = switch (paymentStatus) {
      'paid' => 'Payé',
      'partial' => 'Partiellement payé',
      'unpaid' => hasInvoice ? 'Non payé' : 'Non facturé',
      'unbilled' => hasInvoice ? 'Non payé' : 'Non facturé',
      _ => hasInvoice ? 'Non payé' : 'Non facturé',
    };
    final color = switch (paymentStatus) {
      'paid' => const Color(0xFF047857),
      'partial' => const Color(0xFFD97706),
      'unpaid' => hasInvoice ? const Color(0xFFBE123C) : _muted,
      'unbilled' => hasInvoice ? const Color(0xFFBE123C) : _muted,
      _ => hasInvoice ? const Color(0xFFBE123C) : _muted,
    };

    return Align(
      alignment: Alignment.centerLeft,
      child: Text(
        label,
        style: TextStyle(
          color: color,
          fontSize: 12,
          fontWeight: FontWeight.w800,
        ),
      ),
    );
  }
}
