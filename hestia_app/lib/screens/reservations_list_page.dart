import 'dart:convert';

import 'package:flutter/material.dart';
import 'package:http/http.dart' as http;

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
  const EditReservationPage({super.key, required this.reservation});

  final Map<String, dynamic> reservation;

  @override
  State<EditReservationPage> createState() => _EditReservationPageState();
}

class _EditReservationPageState extends State<EditReservationPage> {
  final _apiClient = const ApiClient();
  final _formKey = GlobalKey<FormState>();
  late final Reservation _reservation;
  late final TextEditingController _nameController;
  late final TextEditingController _phoneController;
  late final TextEditingController _emailController;
  late DateTime _checkIn;
  late DateTime _checkOut;
  late int _extraBeds;
  late int _extraMattresses;
  final List<Map<String, dynamic>> _selectedRooms = [];
  List<Map<String, dynamic>> _availableRooms = [];
  bool _isLoadingRooms = true;
  bool _isSaving = false;
  String? _errorMessage;

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
    _fetchRooms();
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

  String get _checkInKey => _checkIn.toIso8601String().substring(0, 10);
  String get _checkOutKey => _checkOut.toIso8601String().substring(0, 10);

  Future<void> _fetchRooms() async {
    setState(() {
      _isLoadingRooms = true;
      _errorMessage = null;
    });

    try {
      final response = await _apiClient.get('/api/available-rooms', {
        'check_in': _checkInKey,
        'check_out': _checkOutKey,
        'exclude_reservation_id': _reservation.id.toString(),
      });
      if (response.statusCode == 200) {
        final decoded = json.decode(response.body) as List<dynamic>;
        final rooms = decoded
            .whereType<Map>()
            .map((room) => Map<String, dynamic>.from(room))
            .toList();
        final availableIds = rooms.map((room) => _asInt(room['id'])).toSet();
        final removedUnavailable = _selectedRooms.any(
          (room) => !availableIds.contains(_asInt(room['id'])),
        );
        _selectedRooms.removeWhere(
          (room) => !availableIds.contains(_asInt(room['id'])),
        );

        rooms.sort(
          (a, b) => (a['room_number'] ?? '').toString().compareTo(
            (b['room_number'] ?? '').toString(),
          ),
        );

        if (mounted) {
          setState(() {
            _availableRooms = rooms;
            if (removedUnavailable) {
              _errorMessage =
                  'Certaines chambres sélectionnées ne sont plus disponibles sur ces dates.';
            }
          });
        }
      } else if (mounted) {
        setState(() => _errorMessage = 'Impossible de charger les chambres.');
      }
    } catch (e) {
      if (mounted) {
        setState(() => _errorMessage = 'Erreur réseau : $e');
      }
    } finally {
      if (mounted) {
        setState(() => _isLoadingRooms = false);
      }
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
    final selected = await showDatePicker(
      context: context,
      initialDate: _checkOut.isAfter(_checkIn)
          ? _checkOut
          : _checkIn.add(const Duration(days: 1)),
      firstDate: _checkIn.add(const Duration(days: 1)),
      lastDate: DateTime.now().add(const Duration(days: 365)),
    );
    if (selected == null) return;

    setState(() => _checkOut = selected);
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

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: AppBar(title: const Text('Modifier la réservation')),
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
                      keyboardType: TextInputType.text,
                      decoration: const InputDecoration(
                        labelText: 'Téléphone',
                        prefixIcon: Icon(Icons.phone),
                      ),
                    ),
                    const SizedBox(height: 12),
                    TextFormField(
                      controller: _emailController,
                      keyboardType: TextInputType.emailAddress,
                      decoration: const InputDecoration(
                        labelText: 'Email / Autre contact',
                        prefixIcon: Icon(Icons.contact_mail),
                      ),
                    ),
                    const SizedBox(height: 12),
                    ListTile(
                      contentPadding: EdgeInsets.zero,
                      leading: const Icon(Icons.login),
                      title: Text('Arrivée : $_checkInKey'),
                      trailing: const Icon(Icons.calendar_today),
                      onTap: _pickCheckIn,
                    ),
                    ListTile(
                      contentPadding: EdgeInsets.zero,
                      leading: const Icon(Icons.logout),
                      title: Text('Départ : $_checkOutKey'),
                      trailing: const Icon(Icons.calendar_today),
                      onTap: _pickCheckOut,
                    ),
                    const SizedBox(height: 12),
                    _QuantitySelector(
                      icon: Icons.bed_outlined,
                      label: 'Lit supplémentaire',
                      unitPrice: 50000,
                      value: _extraBeds,
                      onChanged: (value) => setState(() => _extraBeds = value),
                    ),
                    const SizedBox(height: 10),
                    _QuantitySelector(
                      icon: Icons.airline_seat_individual_suite_outlined,
                      label: 'Matelas supplémentaire',
                      unitPrice: 30000,
                      value: _extraMattresses,
                      onChanged: (value) =>
                          setState(() => _extraMattresses = value),
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
                        itemCount: _availableRooms.length,
                        itemBuilder: (context, index) {
                          final room = _availableRooms[index];
                          final roomId = _asInt(room['id']);
                          final isSelected = _selectedRooms.any(
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
                              'Tarif : ${formatPrice(_getRoomPrice(room))} Ar / nuit',
                              style: const TextStyle(
                                color: _muted,
                                fontWeight: FontWeight.w700,
                              ),
                            ),
                            onChanged: (value) {
                              setState(() {
                                if (value == true) {
                                  _selectedRooms.add(room);
                                } else {
                                  _selectedRooms.removeWhere(
                                    (selected) =>
                                        _asInt(selected['id']) == roomId,
                                  );
                                }
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
  List<dynamic> _reservations = [];
  bool _isLoading = true;
  late DateTime _selectedDate;
  bool _showAllDates = false;
  String _statusFilter = 'pending';
  String _searchQuery = '';

  @override
  void initState() {
    super.initState();
    _selectedDate = _normalizeSelectedDate(
      widget.initialDate ?? DateTime.now(),
    );
    _fetchReservations();
  }

  bool get _isAdmin => widget.role == 'admin';

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

  Future<void> _fetchReservations() async {
    setState(() => _isLoading = true);
    try {
      String dateParam = _showAllDates
          ? 'all'
          : _selectedDate.toIso8601String().substring(0, 10);
      final cacheBust = DateTime.now().millisecondsSinceEpoch;
      final response = await http.get(
        Uri.parse(
          '$baseUrl/api/reservations/all?date=$dateParam&_ts=$cacheBust',
        ),
      );
      if (response.statusCode == 200) {
        List<dynamic> data = json.decode(response.body);
        // TRI AUTOMATIQUE PAR ORDRE ALPHABÉTIQUE (Nom du client)
        data.sort((a, b) {
          String nameA = (a['client_name'] ?? '').toString().toLowerCase();
          String nameB = (b['client_name'] ?? '').toString().toLowerCase();
          return nameA.compareTo(nameB);
        });
        setState(() {
          _reservations = data;
        });
      }
    } catch (e) {
      debugPrint("Error fetching reservations: $e");
    }
    setState(() => _isLoading = false);
  }

  Future<void> _updateStatus(dynamic id, String newStatus) async {
    try {
      final response = await http.post(
        Uri.parse('$baseUrl/api/bookings/update-status'),
        headers: {'Content-Type': 'application/json'},
        body: json.encode({
          'id': id,
          'status': newStatus,
          if (newStatus == 'annule') 'cancelled_by_name': widget.userName,
        }),
      );
      if (!mounted) return;
      if (response.statusCode == 200) {
        _fetchReservations();
        ScaffoldMessenger.of(context).showSnackBar(
          const SnackBar(
            content: Text('Statut mis à jour !'),
            backgroundColor: Colors.green,
            duration: Duration(seconds: 1),
          ),
        );
      } else {
        ScaffoldMessenger.of(context).showSnackBar(
          SnackBar(
            content: Text('Erreur ${response.statusCode}'),
            backgroundColor: Colors.red,
          ),
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

  bool _isUpcomingReservation(Map<String, dynamic> reservation) {
    final rawDate = reservation['check_out']?.toString();
    if (rawDate == null || rawDate.isEmpty) return false;
    final checkOut = DateTime.tryParse(rawDate);
    if (checkOut == null) return false;
    final now = DateTime.now();
    final today = DateTime(now.year, now.month, now.day);
    final checkOutDate = DateTime(checkOut.year, checkOut.month, checkOut.day);
    return checkOutDate.isAfter(today) || checkOutDate.isAtSameMomentAs(today);
  }

  Future<void> _openEditReservation(Map<String, dynamic> reservation) async {
    final updated = await Navigator.push<bool>(
      context,
      _reservationRoute(EditReservationPage(reservation: reservation)),
    );
    if (updated == true) {
      _fetchReservations();
    }
  }

  Future<void> _openFolio(Map<String, dynamic> reservation) async {
    final isAdmin = _isAdmin;
    final isCheckedIn = (reservation['status'] ?? '').toString() == 'arrive';
    if (!isAdmin && !isCheckedIn) {
      if (mounted) {
        ScaffoldMessenger.of(context).showSnackBar(
          const SnackBar(
            content: Text(
              'Le folio est accessible uniquement après check-in, sauf pour les administrateurs.',
            ),
          ),
        );
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
    final displayedReservations = _reservations.where((reservation) {
      final clientName = (reservation['client_name'] ?? '')
          .toString()
          .toLowerCase();
      final matchesSearch = query.isEmpty || clientName.contains(query);
      final status = (reservation['status'] ?? '').toString();
      final paymentStatus = (reservation['payment_status'] ?? 'unbilled')
          .toString();
      final matchesStatus = switch (_statusFilter) {
        'pending' => status == 'en_attente',
        'unpaid' =>
          status == 'arrive' &&
              (paymentStatus == 'unpaid' ||
                  paymentStatus == 'partial' ||
                  paymentStatus == 'unbilled'),
        _ => true,
      };
      return matchesSearch && matchesStatus;
    }).toList();

    return Scaffold(
      appBar: AppBar(
        title: const Text('Réservations'),
        actions: [
          IconButton(
            onPressed: () {
              setState(() {
                _showAllDates = false;
                _statusFilter = 'pending';
              });
              _fetchReservations();
            },
            icon: const Icon(Icons.refresh),
          ),
          TextButton(
            onPressed: () {
              setState(() {
                _showAllDates = !_showAllDates;
              });
              _fetchReservations();
            },
            child: Text(
              _showAllDates ? 'Filtrer par date' : 'Voir Tout',
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
                      if (value == 'unpaid') {
                        _selectedDate = _todayOnly.subtract(
                          const Duration(days: 1),
                        );
                        _showAllDates = false;
                      }
                    });
                    _fetchReservations();
                  },
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
                    TextButton(
                      onPressed: () {
                        setState(() {
                          _showAllDates = !_showAllDates;
                          _statusFilter = _showAllDates ? 'all' : 'pending';
                        });
                        _fetchReservations();
                      },
                      child: Text(
                        _showAllDates ? 'Filtrer par date' : 'Voir tout',
                        style: const TextStyle(
                          color: _primaryDark,
                          fontWeight: FontWeight.w800,
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
                      final canEdit = _isUpcomingReservation(res);
                      final paymentStatus =
                          (res['payment_status'] ?? 'unbilled').toString();
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
                                  if (canEdit)
                                    IconButton(
                                      tooltip: 'Modifier',
                                      onPressed: () =>
                                          _openEditReservation(res),
                                      icon: const Icon(Icons.edit_outlined),
                                      color: _primaryDark,
                                    ),
                                  if (_isAdmin ||
                                      (res['status'] ?? '').toString() ==
                                          'arrive')
                                    IconButton(
                                      tooltip: 'Folio',
                                      onPressed: () => _openFolio(res),
                                      icon: const Icon(
                                        Icons.receipt_long_outlined,
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
                              const SizedBox(height: 10),
                              Text(
                                (res['email'] == 'N/A' ||
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
                                processedBy: res['latest_payment_processed_by']
                                    ?.toString(),
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
                                  const SizedBox(width: 10),
                                  _ReservationStatusPills(
                                    status: (res['status'] ?? '').toString(),
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
      'annule' => 'Annulé',
      _ => 'En attente',
    };
    final color = switch (value) {
      'arrive' => const Color(0xFF047857),
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

class _ReservationStatusPills extends StatelessWidget {
  const _ReservationStatusPills({
    required this.status,
    required this.onChanged,
  });

  final String status;
  final ValueChanged<String> onChanged;

  @override
  Widget build(BuildContext context) {
    String normalized = status;
    if (normalized != 'en_attente' &&
        normalized != 'arrive' &&
        normalized != 'annule') {
      normalized = 'en_attente';
    }

    return Wrap(
      spacing: 6,
      runSpacing: 6,
      alignment: WrapAlignment.end,
      children: [
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
        _StatusChoiceChip(
          label: 'Annulé',
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
    required this.unitPrice,
    required this.value,
    required this.onChanged,
  });

  final IconData icon;
  final String label;
  final int unitPrice;
  final int value;
  final ValueChanged<int> onChanged;

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
                Text(
                  '${formatPrice(unitPrice)} Ar / unité',
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
            onPressed: () => onChanged(value + 1),
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
      ],
    );
  }
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
    required this.processedBy,
  });

  final String paymentStatus;
  final String? processedBy;

  @override
  Widget build(BuildContext context) {
    final label = switch (paymentStatus) {
      'paid' => 'Payé',
      'partial' => 'Partiellement payé',
      'unpaid' => 'Non payé',
      _ => 'Non facturé',
    };
    final color = switch (paymentStatus) {
      'paid' => const Color(0xFF047857),
      'partial' => const Color(0xFFD97706),
      'unpaid' => const Color(0xFFBE123C),
      _ => _muted,
    };

    return Align(
      alignment: Alignment.centerLeft,
      child: Text(
        processedBy == null || processedBy!.isEmpty
            ? label
            : '$label • ${processedBy!}',
        style: TextStyle(
          color: color,
          fontSize: 12,
          fontWeight: FontWeight.w800,
        ),
      ),
    );
  }
}
