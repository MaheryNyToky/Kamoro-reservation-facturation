import 'dart:convert';
import 'dart:typed_data';

import 'package:flutter/material.dart';
import 'package:http/http.dart' as http;
import 'package:flutter/services.dart';
import 'package:printing/printing.dart';
import 'package:shared_preferences/shared_preferences.dart';
import 'package:share_plus/share_plus.dart';

import '../core/app_config.dart';
import '../core/formatters.dart';
import '../services/pdf_download.dart';

const String baseUrl = AppConfig.apiBaseUrl;
const Color _primary = Color(0xFF0F766E);
const Color _primaryDark = Color(0xFF134E4A);
const Color _ink = Color(0xFF0F172A);
const Color _muted = Color(0xFF64748B);
const Color _border = Color(0xFFE2E8F0);
const Color _rose = Color(0xFFBE123C);

class FolioPage extends StatefulWidget {
  const FolioPage({
    super.key,
    required this.reservation,
    required this.userName,
    required this.role,
    this.pricingMode = 'fixed',
  });

  final Map<String, dynamic> reservation;
  final String userName;
  final String role;
  final String pricingMode;

  @override
  State<FolioPage> createState() => _FolioPageState();
}

class _FolioPageState extends State<FolioPage> {
  Map<String, dynamic>? _folio;
  bool _isLoading = true;
  bool _isBusy = false;
  String _documentType = 'facture';
  bool _bookingInvoiceInEuro = false;
  String _billingMode = 'grouped';
  int? _selectedInvoiceId;
  final Set<int> _selectedInvoiceIds = {};

  int get _reservationId => _asInt(widget.reservation['id']);
  int get _invoiceId => _selectedInvoiceId ?? _asInt(_folio?['id']);
  bool get _isFinalized => _folio?['status'] == 'finalized';
  bool get _hasPdf => (_folio?['pdf_url'] ?? '').toString().isNotEmpty;
  bool get _canEditPayments =>
      widget.role != 'receptionist' || _paymentModificationCount < 1;
  int get _paymentModificationCount =>
      _asInt(_folio?['payment_modification_count']);
  bool get _isBookingReservation {
    final folioFlag = _folio?['is_booking'];
    final reservationFlag = widget.reservation['is_booking'];
    final source = widget.reservation['source']?.toString();

    return folioFlag == true || reservationFlag == true || source == 'Booking';
  }

  bool get _isOrganizationReservation {
    final folioBookingType = _folio?['booking_type']?.toString();
    final reservationBookingType =
        widget.reservation['booking_type']?.toString() ??
        widget.reservation['bookingType']?.toString();

    return folioBookingType == 'organization' ||
        reservationBookingType == 'organization';
  }

  List<Map<String, dynamic>> get _availableInvoices {
    return (_folio?['invoices'] as List<dynamic>? ?? [])
        .whereType<Map>()
        .map((invoice) => Map<String, dynamic>.from(invoice))
        .toList();
  }

  List<Map<String, dynamic>> get _roomInvoices {
    return _availableInvoices
        .where(
          (invoice) =>
              (invoice['invoice_kind']?.toString() ?? 'master') != 'master',
        )
        .toList();
  }

  List<Map<String, dynamic>> get _roomBookings {
    return (_folio?['room_bookings'] as List<dynamic>? ?? [])
        .whereType<Map>()
        .map((room) => Map<String, dynamic>.from(room))
        .toList();
  }

  Map<String, dynamic>? get _masterInvoice {
    for (final invoice in _availableInvoices) {
      if ((invoice['invoice_kind']?.toString() ?? 'master') == 'master') {
        return invoice;
      }
    }
    return null;
  }

  int? get _activeInvoiceIdForMode {
    if (_isOrganizationReservation && _billingMode == 'individual') {
      if (_selectedInvoiceId != null && _selectedInvoiceId! > 0) {
        return _selectedInvoiceId;
      }
      final firstRoom = _roomInvoices.isEmpty ? null : _roomInvoices.first;
      final firstRoomId = _asInt(firstRoom?['id']);
      if (firstRoomId > 0) return firstRoomId;
    }

    final groupedInvoiceId = _invoiceIdForBillingMode('grouped');
    if (groupedInvoiceId != null && groupedInvoiceId > 0) {
      return groupedInvoiceId;
    }
    return _selectedInvoiceId ?? _asInt(_folio?['id']);
  }

  int? _invoiceIdForBillingMode(String billingMode) {
    final normalized = billingMode == 'individual' ? 'individual' : 'grouped';
    if (normalized == 'grouped') {
      final masterId = _asInt(_masterInvoice?['id']);
      return masterId > 0 ? masterId : _selectedInvoiceId;
    }

    final child = _roomInvoices.isEmpty ? null : _roomInvoices.first;
    final childId = _asInt(child?['id']);
    return childId > 0 ? childId : _selectedInvoiceId;
  }

  Set<int> get _effectiveIndividualSelection {
    return _selectedInvoiceIds
        .where(
          (id) =>
              _roomInvoices.any((invoice) => _asInt(invoice['id']) == id) ||
              _roomBookings.any((room) => _asInt(room['id']) == id),
        )
        .toSet();
  }

  List<Map<String, dynamic>> get _individualSelectionEntries {
    if (_roomInvoices.isNotEmpty) {
      return _roomInvoices.map((invoice) {
        final bookingRoomId = _asInt(invoice['booking_room_id']);
        final roomLabel = bookingRoomId > 0
            ? _roomLabelForBooking(bookingRoomId, _roomBookings)
            : null;
        return {
          'id': bookingRoomId > 0 ? bookingRoomId : _asInt(invoice['id']),
          'booking_room_id': bookingRoomId,
          'invoice_id': _asInt(invoice['id']),
          'title': roomLabel ?? 'Chambre',
          'subtitle': _invoiceTileSubtitle(invoice, null),
          'invoice': invoice,
        };
      }).toList();
    }

    return _roomBookings.map((room) {
      final roomId = _asInt(room['id']);
      final roomNumber = room['room_number']?.toString() ?? '';
      final type = room['type']?.toString() ?? '';
      final title = roomNumber.isNotEmpty
          ? (type.isNotEmpty ? '$roomNumber - $type' : roomNumber)
          : (type.isNotEmpty ? type : 'Chambre');
      final subtitleParts = <String>[
        if ((room['occupant_name'] ?? '').toString().trim().isNotEmpty)
          (room['occupant_name'] ?? '').toString().trim(),
        if ((room['invoice_id'] ?? '').toString().isNotEmpty)
          'Facture déjà liée',
        if ((room['price_snapshot_ariary'] ?? 0) != null)
          '${formatPrice(_asInt(room['price_snapshot_ariary']))} Ar',
      ];
      return {
        'id': roomId,
        'booking_room_id': roomId,
        'title': title,
        'subtitle': subtitleParts.join(' • '),
        'room': room,
      };
    }).toList();
  }

  List<int> _resolveIndividualInvoiceIdsFromSelection() {
    final selectedIds = _effectiveIndividualSelection.toList();
    if (selectedIds.isEmpty) return const [];

    final invoiceByBookingId = {
      for (final invoice in _roomInvoices)
        _asInt(invoice['booking_room_id']): _asInt(invoice['id']),
    };
    return selectedIds
        .map((selectedId) => invoiceByBookingId[selectedId] ?? selectedId)
        .where((id) => id > 0)
        .toList();
  }

  void _applyFolioPayload(
    Map<String, dynamic> payload, {
    String? preserveBillingMode,
    Set<int>? preserveSelectionIds,
  }) {
    final invoices = (payload['invoices'] as List<dynamic>? ?? [])
        .whereType<Map>()
        .map((invoice) => Map<String, dynamic>.from(invoice))
        .toList();
    final payloadBillingMode = (payload['billing_mode'] ?? 'grouped')
        .toString();
    final selectedInvoiceId = _asInt(payload['selected_invoice_id']);
    final currentInvoiceId = _asInt(payload['id']);
    final masterInvoice = invoices.firstWhere(
      (invoice) =>
          (invoice['invoice_kind']?.toString() ?? 'master') == 'master',
      orElse: () => const <String, dynamic>{},
    );
    final roomInvoiceIds = invoices
        .where(
          (invoice) =>
              (invoice['invoice_kind']?.toString() ?? 'master') != 'master',
        )
        .map((invoice) => _asInt(invoice['id']))
        .where((id) => id > 0)
        .toList();
    final roomBookingIds = (payload['room_bookings'] as List<dynamic>? ?? [])
        .whereType<Map>()
        .map((room) => _asInt(room['id']))
        .where((id) => id > 0)
        .toList();
    final roomInvoiceBookingIds = invoices
        .where(
          (invoice) =>
              (invoice['invoice_kind']?.toString() ?? 'master') != 'master',
        )
        .map((invoice) => _asInt(invoice['booking_room_id']))
        .where((id) => id > 0)
        .toList();
    final incomingSelectionIds =
        preserveSelectionIds?.where((id) => id > 0).toSet() ?? {};

    setState(() {
      _folio = payload;
      _documentType = (_folio?['document_type'] ?? 'facture').toString();
      _billingMode =
          preserveBillingMode ??
          (payloadBillingMode == 'per_room' ? 'individual' : 'grouped');

      final nextSelectedInvoiceId = selectedInvoiceId > 0
          ? selectedInvoiceId
          : currentInvoiceId > 0
          ? currentInvoiceId
          : _selectedInvoiceId;
      _selectedInvoiceId = nextSelectedInvoiceId;

      if (_isOrganizationReservation && _billingMode == 'individual') {
        if (incomingSelectionIds.isNotEmpty) {
          final resolvedSelections = incomingSelectionIds
              .where(
                (id) =>
                    roomBookingIds.contains(id) ||
                    roomInvoiceBookingIds.contains(id),
              )
              .toSet();
          _selectedInvoiceIds
            ..clear()
            ..addAll(resolvedSelections);
          _selectedInvoiceId = resolvedSelections.isNotEmpty
              ? resolvedSelections.first
              : _selectedInvoiceId;
        } else {
          _selectedInvoiceIds
            ..clear()
            ..addAll(
              roomBookingIds.isNotEmpty
                  ? roomBookingIds
                  : roomInvoiceBookingIds,
            );
          if (_selectedInvoiceId == null || _selectedInvoiceId! <= 0) {
            _selectedInvoiceId = roomBookingIds.isNotEmpty
                ? roomBookingIds.first
                : roomInvoiceBookingIds.isNotEmpty
                ? roomInvoiceBookingIds.first
                : roomInvoiceIds.isNotEmpty
                ? roomInvoiceIds.first
                : roomBookingIds.isNotEmpty
                ? roomBookingIds.first
                : null;
          }
        }
      } else {
        _selectedInvoiceIds.clear();
        if (_selectedInvoiceId == null || _selectedInvoiceId! <= 0) {
          final masterId = _asInt(masterInvoice['id']);
          _selectedInvoiceId = masterId > 0 ? masterId : _selectedInvoiceId;
        }
      }
    });
  }

  List<int> _invoiceIdsForPdfGeneration() {
    if (_isOrganizationReservation && _billingMode == 'individual') {
      return _effectiveIndividualSelection.toList();
    }
    final groupedInvoiceId = _invoiceIdForBillingMode('grouped');
    return groupedInvoiceId == null || groupedInvoiceId <= 0
        ? const []
        : <int>[groupedInvoiceId];
  }

  int _stayNights() {
    final rawCheckIn =
        _folio?['check_in']?.toString() ??
        widget.reservation['check_in']?.toString() ??
        widget.reservation['check_in_date']?.toString();
    final rawCheckOut =
        _folio?['check_out']?.toString() ??
        widget.reservation['check_out']?.toString() ??
        widget.reservation['check_out_date']?.toString();

    final checkIn = DateTime.tryParse(rawCheckIn ?? '');
    final checkOut = DateTime.tryParse(rawCheckOut ?? '');
    if (checkIn == null || checkOut == null) return 1;

    final nights = checkOut.difference(checkIn).inDays;
    return nights < 1 ? 1 : nights;
  }

  bool get _canAccess =>
      widget.role != 'receptionist' ||
      widget.reservation['status']?.toString() == 'arrive';

  @override
  void initState() {
    super.initState();
    _fetchFolio();
  }

  Future<void> _fetchFolio({
    int? invoiceId,
    String? preserveBillingMode,
    Set<int>? preserveSelectionIds,
  }) async {
    if (!_canAccess) {
      if (mounted) {
        setState(() => _isLoading = false);
      }
      return;
    }

    setState(() => _isLoading = true);
    final cacheKey = 'folio_cache:$_reservationId:${invoiceId ?? "default"}';
    try {
      final uri = Uri.parse('$baseUrl/api/reservations/$_reservationId/folio')
          .replace(
            queryParameters: invoiceId == null
                ? null
                : {'invoice_id': invoiceId.toString()},
          );
      final response = await http.get(uri).timeout(const Duration(seconds: 5));
      if (!mounted) return;
      if (response.statusCode == 200) {
        final payload = Map<String, dynamic>.from(json.decode(response.body));
        final prefs = await SharedPreferences.getInstance();
        await prefs.setString(cacheKey, json.encode(payload));
        _applyFolioPayload(
          payload,
          preserveBillingMode: preserveBillingMode,
          preserveSelectionIds: preserveSelectionIds,
        );
      } else {
        final prefs = await SharedPreferences.getInstance();
        final cached = prefs.getString(cacheKey);
        if (cached != null) {
          _applyFolioPayload(
            Map<String, dynamic>.from(json.decode(cached)),
            preserveBillingMode: preserveBillingMode,
            preserveSelectionIds: preserveSelectionIds,
          );
          _showMessage('Mode dégradé: folio local affiché.', isError: false);
        } else {
          _showMessage('Impossible de charger le folio.', isError: true);
        }
      }
    } catch (e) {
      final prefs = await SharedPreferences.getInstance();
      final cached = prefs.getString(cacheKey);
      if (cached != null) {
        if (mounted) {
          _applyFolioPayload(
            Map<String, dynamic>.from(json.decode(cached)),
            preserveBillingMode: preserveBillingMode,
            preserveSelectionIds: preserveSelectionIds,
          );
          _showMessage('Mode dégradé: folio local affiché.', isError: false);
        }
      } else if (mounted) {
        _showMessage('Erreur réseau : $e', isError: true);
      }
    } finally {
      if (mounted) setState(() => _isLoading = false);
    }
  }

  Future<void> _addExtra() async {
    final result = await _showItemDialog();
    if (result == null) return;

    await _sendJson(
      'POST',
      '/api/invoices/$_invoiceId/items',
      result,
      successMessage: 'Extra ajouté.',
    );
  }

  Future<void> _addPayment() async {
    final result = await _showPaymentDialog();
    if (result == null) return;

    await _sendJson(
      'POST',
      '/api/invoices/$_invoiceId/payments',
      {
        ...result,
        'processed_by_name': widget.userName,
        'processed_by_role': widget.role,
      },
      successMessage: 'Paiement enregistré.',
    );
  }

  Future<void> _editPayment(Map<String, dynamic> payment) async {
    final result = await _showPaymentDialog(
      payment: payment,
      title: 'Modifier le paiement',
    );
    if (result == null) return;

    await _sendJson(
      'PUT',
      '/api/invoices/$_invoiceId/payments/${payment['id']}',
      {
        ...result,
        'processed_by_name': widget.userName,
        'processed_by_role': widget.role,
      },
      successMessage: 'Paiement modifié.',
    );
  }

  Future<void> _generatePdf() async {
    final discountText = _discountController.text.trim();
    final discountValue = int.tryParse(discountText) ?? 0;
    final payload = <String, dynamic>{
      'pricing_mode': widget.pricingMode,
      'document_type': _documentType,
      'currency_mode': _isBookingReservation && _bookingInvoiceInEuro
          ? 'euro'
          : 'ariary',
    };

    payload['billing_mode'] = _isOrganizationReservation
        ? _billingMode
        : 'grouped';

    if (widget.role != 'receptionist' && discountValue > 0) {
      payload['discount_mode'] = _discountMode;
      payload['discount_value'] = discountValue;
    }

    payload['actor_role'] = widget.role;

    if (_isBusy) return;
    setState(() => _isBusy = true);
    try {
      final isOrganizationIndividual =
          _isOrganizationReservation && _billingMode == 'individual';
      final groupedInvoiceId =
          _invoiceIdForBillingMode('grouped') ?? _invoiceId;

      if (isOrganizationIndividual &&
          _roomInvoices.isEmpty &&
          _roomBookings.isNotEmpty) {
        await _postGeneratePdf(groupedInvoiceId, payload);
        await _fetchFolio(
          invoiceId: groupedInvoiceId,
          preserveBillingMode: 'individual',
        );
      }

      final invoiceIds = isOrganizationIndividual
          ? _resolveIndividualInvoiceIdsFromSelection()
          : <int>[groupedInvoiceId];

      if (invoiceIds.isEmpty) {
        _showMessage(
          'Sélectionnez au moins une facture de chambre avant de générer le PDF.',
          isError: true,
        );
        return;
      }

      for (final invoiceId in invoiceIds) {
        await _postGeneratePdf(invoiceId, payload);
      }

      final previousSelectionIds = isOrganizationIndividual
          ? _effectiveIndividualSelection
          : <int>{};
      await _fetchFolio(
        invoiceId: isOrganizationIndividual
            ? invoiceIds.first
            : _activeInvoiceIdForMode,
        preserveBillingMode: isOrganizationIndividual ? 'individual' : null,
        preserveSelectionIds: previousSelectionIds,
      );
      _showMessage(
        invoiceIds.length > 1
            ? (_documentType == 'proforma'
                  ? 'Proformas PDF mises à jour.'
                  : 'Factures PDF mises à jour.')
            : (_documentType == 'proforma'
                  ? 'Proforma PDF mise à jour.'
                  : 'Facture PDF mise à jour.'),
      );
    } catch (e) {
      if (mounted) _showMessage('Erreur réseau : $e', isError: true);
    } finally {
      if (mounted) setState(() => _isBusy = false);
    }
  }

  Future<void> _postGeneratePdf(
    int invoiceId,
    Map<String, dynamic> payload,
  ) async {
    final uri = Uri.parse('$baseUrl/api/invoices/$invoiceId/generate-pdf');
    final response = await http.post(
      uri,
      headers: const {'Content-Type': 'application/json'},
      body: json.encode(payload),
    );
    if (!mounted) return;
    if (response.statusCode < 200 || response.statusCode >= 300) {
      final decoded = response.body.isNotEmpty
          ? json.decode(response.body)
          : null;
      final message = decoded is Map && decoded['message'] != null
          ? decoded['message'].toString()
          : 'Erreur ${response.statusCode}';
      throw Exception(message);
    }
  }

  Future<void> _sendEmail() async {
    if (!_hasPdf) {
      _showMessage('Générez la facture avant l’envoi email.', isError: true);
      return;
    }

    final email = await _showEmailDialog();
    if (email == null) return;

    await _sendJson(
      'POST',
      '/api/invoices/$_invoiceId/send-email',
      {'email': email},
      successMessage: 'Facture envoyée par email.',
    );
  }

  Future<void> _downloadInvoice() async {
    try {
      final bytes = await _downloadPdfBytes();
      final filename = '${_folio?['invoice_number'] ?? 'facture'}.pdf';
      if (!mounted) return;
      final message = await savePdfToDownloads(bytes, filename);
      _showMessage(message);
    } catch (e) {
      if (mounted) _showMessage(e.toString(), isError: true);
    }
  }

  Future<void> _sendJson(
    String method,
    String path,
    Map<String, dynamic> body, {
    required String successMessage,
  }) async {
    if (_isBusy) return;

    setState(() => _isBusy = true);
    try {
      final uri = Uri.parse('$baseUrl$path');
      final headers = {'Content-Type': 'application/json'};
      final encodedBody = json.encode(body);
      final response = switch (method) {
        'PUT' => await http.put(uri, headers: headers, body: encodedBody),
        _ => await http.post(uri, headers: headers, body: encodedBody),
      };
      if (!mounted) return;

      final decoded = response.body.isNotEmpty
          ? json.decode(response.body)
          : null;
      if (response.statusCode >= 200 && response.statusCode < 300) {
        if (decoded is Map && decoded['invoice'] is Map) {
          _applyFolioPayload(
            Map<String, dynamic>.from(decoded['invoice'] as Map),
          );
        } else {
          await _fetchFolio(invoiceId: _invoiceId);
        }
        _showMessage(successMessage);
      } else {
        final message = decoded is Map && decoded['message'] != null
            ? decoded['message'].toString()
            : 'Erreur ${response.statusCode}';
        _showMessage(message, isError: true);
      }
    } catch (e) {
      if (mounted) _showMessage('Erreur réseau : $e', isError: true);
    } finally {
      if (mounted) setState(() => _isBusy = false);
    }
  }

  Future<Uint8List> _downloadPdfBytes() async {
    if (!_hasPdf) {
      throw Exception('Aucune facture PDF disponible.');
    }
    final response = await http.get(Uri.parse(_folio!['pdf_url'].toString()));
    if (response.statusCode != 200) {
      throw Exception(
        'Téléchargement PDF impossible (${response.statusCode}).',
      );
    }
    return response.bodyBytes;
  }

  Future<void> _openPdfPreview() async {
    try {
      final bytes = await _downloadPdfBytes();
      if (!mounted) return;
      await Navigator.push(
        context,
        MaterialPageRoute(
          builder: (_) => InvoicePdfPage(
            title: _folio?['invoice_number']?.toString() ?? 'Facture',
            bytes: bytes,
          ),
        ),
      );
    } catch (e) {
      if (mounted) _showMessage(e.toString(), isError: true);
    }
  }

  Future<void> _printPdf() async {
    try {
      final bytes = await _downloadPdfBytes();
      await Printing.layoutPdf(onLayout: (_) async => bytes);
    } catch (e) {
      if (mounted) _showMessage(e.toString(), isError: true);
    }
  }

  Future<void> _sharePdf() async {
    try {
      final bytes = await _downloadPdfBytes();
      final name = '${_folio?['invoice_number'] ?? 'facture'}.pdf';
      await SharePlus.instance.share(
        ShareParams(
          files: [
            XFile.fromData(bytes, name: name, mimeType: 'application/pdf'),
          ],
          text: 'Facture $name',
        ),
      );
    } catch (e) {
      if (mounted) _showMessage(e.toString(), isError: true);
    }
  }

  Future<Map<String, dynamic>?> _showItemDialog() async {
    final descriptionController = TextEditingController();
    final amountController = TextEditingController();
    final quantityController = TextEditingController(text: '1');
    final stayNights = _stayNights();

    return showDialog<Map<String, dynamic>>(
      context: context,
      builder: (context) => AlertDialog(
        title: const Text('Ajouter un extra'),
        content: SingleChildScrollView(
          child: Column(
            mainAxisSize: MainAxisSize.min,
            children: [
              TextField(
                controller: descriptionController,
                decoration: const InputDecoration(
                  labelText: 'Consommation ou service',
                  prefixIcon: Icon(Icons.room_service_outlined),
                ),
              ),
              const SizedBox(height: 12),
              TextField(
                controller: amountController,
                keyboardType: TextInputType.number,
                inputFormatters: const [AriaryInputFormatter()],
                decoration: const InputDecoration(
                  labelText: 'Prix unitaire (Ar)',
                  prefixIcon: Icon(Icons.payments_outlined),
                ),
              ),
              const SizedBox(height: 12),
              TextField(
                controller: quantityController,
                keyboardType: TextInputType.number,
                decoration: const InputDecoration(
                  labelText: 'Quantité',
                  prefixIcon: Icon(Icons.numbers),
                ),
              ),
              const SizedBox(height: 8),
              Text(
                'Les suppléments lit et matelas seront multipliés par $stayNights nuit(s).',
                style: const TextStyle(
                  fontSize: 12,
                  color: _muted,
                  fontWeight: FontWeight.w600,
                ),
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
              final description = descriptionController.text.trim();
              final amount = parseAriaryAmount(amountController.text);
              final quantity =
                  int.tryParse(quantityController.text.trim()) ?? 1;
              if (description.isEmpty || amount <= 0 || quantity <= 0) return;
              final normalizedDescription = description.toLowerCase();
              final appliesPerNight =
                  normalizedDescription == 'lit supplémentaire' ||
                  normalizedDescription == 'lit supplementaire' ||
                  normalizedDescription == 'matelas supplémentaire' ||
                  normalizedDescription == 'matelas supplementaire';
              Navigator.pop(context, {
                'description': description,
                'type': 'extra',
                'amount_ariary': amount,
                'quantity': appliesPerNight ? quantity * stayNights : quantity,
              });
            },
            child: const Text('Ajouter'),
          ),
        ],
      ),
    );
  }

  Future<Map<String, dynamic>?> _showPaymentDialog({
    Map<String, dynamic>? payment,
    String title = 'Enregistrer un paiement',
  }) async {
    final amountController = TextEditingController(
      text: payment != null
          ? formatPrice(
              _asInt(
                payment['amount_received_ariary'] ?? payment['amount_ariary'],
              ),
            )
          : '',
    );
    final referenceController = TextEditingController();
    referenceController.text = payment?['reference']?.toString() ?? '';
    String method = payment?['payment_method']?.toString() ?? 'Espèces';
    String operator = payment?['payment_operator']?.toString() ?? 'mvola';
    const methods = [
      'Espèces',
      'Carte Bancaire',
      'Mobile Money',
      'Chèque',
      'Virement',
    ];
    const operators = ['mvola', 'orange money', 'airtel money'];

    return showDialog<Map<String, dynamic>>(
      context: context,
      builder: (context) => StatefulBuilder(
        builder: (context, setDialogState) => AlertDialog(
          title: Text(title),
          content: SingleChildScrollView(
            child: Column(
              mainAxisSize: MainAxisSize.min,
              children: [
                TextField(
                  controller: amountController,
                  keyboardType: TextInputType.number,
                  inputFormatters: const [AriaryInputFormatter()],
                  decoration: const InputDecoration(
                    labelText: 'Montant (Ar)',
                    prefixIcon: Icon(Icons.payments_outlined),
                  ),
                ),
                const SizedBox(height: 12),
                DropdownButtonFormField<String>(
                  initialValue: method,
                  decoration: const InputDecoration(
                    labelText: 'Méthode',
                    prefixIcon: Icon(Icons.credit_card),
                  ),
                  items: methods
                      .map(
                        (value) =>
                            DropdownMenuItem(value: value, child: Text(value)),
                      )
                      .toList(),
                  onChanged: (value) => setDialogState(() {
                    method = value ?? method;
                    if (method != 'Mobile Money') {
                      operator = 'mvola';
                    }
                  }),
                ),
                if (method == 'Mobile Money') ...[
                  const SizedBox(height: 12),
                  DropdownButtonFormField<String>(
                    initialValue: operator,
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
                    onChanged: (value) =>
                        setDialogState(() => operator = value ?? operator),
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
              onPressed: () => Navigator.pop(context),
              child: const Text('Annuler'),
            ),
            ElevatedButton(
              onPressed: () {
                final amount = parseAriaryAmount(amountController.text);
                if (amount <= 0) return;
                Navigator.pop(context, {
                  'amount_ariary': amount,
                  'payment_method': method,
                  'payment_operator': method == 'Mobile Money'
                      ? operator
                      : null,
                  'reference': referenceController.text.trim(),
                });
              },
              child: const Text('Encaisser'),
            ),
          ],
        ),
      ),
    );
  }

  String _discountMode = 'amount';
  final TextEditingController _discountController = TextEditingController();

  @override
  void dispose() {
    _discountController.dispose();
    super.dispose();
  }

  Future<String?> _showEmailDialog() {
    final existing = widget.reservation['email']?.toString();
    final controller = TextEditingController(
      text: existing == null || existing == 'N/A' ? '' : existing,
    );

    return showDialog<String>(
      context: context,
      builder: (context) => AlertDialog(
        title: const Text('Envoyer la facture'),
        content: TextField(
          controller: controller,
          keyboardType: TextInputType.emailAddress,
          decoration: const InputDecoration(
            labelText: 'Email client',
            prefixIcon: Icon(Icons.mail_outline),
          ),
        ),
        actions: [
          TextButton(
            onPressed: () => Navigator.pop(context),
            child: const Text('Annuler'),
          ),
          ElevatedButton(
            onPressed: () {
              final email = controller.text.trim();
              if (email.isEmpty) return;
              Navigator.pop(context, email);
            },
            child: const Text('Envoyer'),
          ),
        ],
      ),
    );
  }

  void _showMessage(String message, {bool isError = false}) {
    ScaffoldMessenger.of(context).showSnackBar(
      SnackBar(
        content: Text(message),
        backgroundColor: isError ? _rose : Colors.green,
      ),
    );
  }

  int _asInt(dynamic value) {
    if (value is int) return value;
    if (value is num) return value.toInt();
    return int.tryParse(value?.toString() ?? '') ?? 0;
  }

  @override
  Widget build(BuildContext context) {
    if (!_canAccess) {
      return Scaffold(
        appBar: AppBar(title: const Text('Folio et facturation')),
        body: Center(
          child: Padding(
            padding: const EdgeInsets.all(24),
            child: Column(
              mainAxisSize: MainAxisSize.min,
              children: [
                const Icon(Icons.lock_outline, size: 48, color: _muted),
                const SizedBox(height: 12),
                const Text(
                  'Le folio est réservé après le check-in, sauf pour les administrateurs.',
                  textAlign: TextAlign.center,
                  style: TextStyle(fontWeight: FontWeight.w700),
                ),
                const SizedBox(height: 16),
                ElevatedButton(
                  onPressed: () => Navigator.pop(context),
                  child: const Text('Retour'),
                ),
              ],
            ),
          ),
        ),
      );
    }

    final items = (_folio?['items'] as List<dynamic>? ?? [])
        .where((item) => (item as Map)['type']?.toString() != 'tax')
        .toList();
    final payments = (_folio?['payments'] as List<dynamic>? ?? []);
    final roomBookings = (_folio?['room_bookings'] as List<dynamic>? ?? [])
        .whereType<Map>()
        .map((room) => Map<String, dynamic>.from(room))
        .toList();

    return Scaffold(
      appBar: AppBar(
        title: const Text('Folio et facturation'),
        actions: [
          IconButton(onPressed: _fetchFolio, icon: const Icon(Icons.refresh)),
        ],
      ),
      body: _isLoading
          ? const Center(child: CircularProgressIndicator())
          : _folio == null
          ? const Center(child: Text('Aucun folio disponible.'))
          : Stack(
              children: [
                Row(
                  children: [
                    Expanded(
                      flex: 2,
                      child: ListView(
                        padding: const EdgeInsets.all(16),
                        children: [
                          _SummaryPanel(
                            folio: _folio!,
                            reservation: widget.reservation,
                            showLoyalty: widget.role != 'receptionist',
                          ),
                          const SizedBox(height: 16),
                          if (_isOrganizationReservation) ...[
                            SegmentedButton<String>(
                              segments: const [
                                ButtonSegment(
                                  value: 'grouped',
                                  label: Text('Groupée'),
                                  icon: Icon(Icons.groups_outlined),
                                ),
                                ButtonSegment(
                                  value: 'individual',
                                  label: Text('Individuelle'),
                                  icon: Icon(Icons.person_outline),
                                ),
                              ],
                              selected: {_billingMode},
                              onSelectionChanged: _isBusy
                                  ? null
                                  : (selection) {
                                      final nextMode = selection.first;
                                      final nextInvoiceId =
                                          _invoiceIdForBillingMode(nextMode);
                                      setState(() {
                                        _billingMode = nextMode;
                                        if (nextInvoiceId != null) {
                                          _selectedInvoiceId = nextInvoiceId;
                                        }
                                        if (nextMode == 'grouped') {
                                          _selectedInvoiceIds.clear();
                                        } else {
                                          _selectedInvoiceIds
                                            ..clear()
                                            ..addAll(
                                              _roomInvoices
                                                  .map(
                                                    (invoice) =>
                                                        _asInt(invoice['id']),
                                                  )
                                                  .where((id) => id > 0),
                                            );
                                        }
                                      });
                                      if (nextInvoiceId != null) {
                                        _fetchFolio(
                                          invoiceId: nextInvoiceId,
                                          preserveBillingMode: nextMode,
                                        );
                                      }
                                    },
                            ),
                            const SizedBox(height: 16),
                            _OrganizationInvoiceSelectionCard(
                              billingMode: _billingMode,
                              invoices: _availableInvoices,
                              individualEntries: _individualSelectionEntries,
                              selectedInvoiceIds: _selectedInvoiceIds,
                              activeInvoiceId: _activeInvoiceIdForMode,
                              onSelectInvoice: _isBusy
                                  ? null
                                  : (invoiceId) {
                                      if (invoiceId <= 0) return;
                                      final selectedInvoice = _availableInvoices
                                          .firstWhere(
                                            (invoice) =>
                                                _asInt(invoice['id']) ==
                                                invoiceId,
                                            orElse: () => const {},
                                          );
                                      final isRoomInvoice =
                                          (selectedInvoice['invoice_kind']
                                                  ?.toString() ??
                                              'master') !=
                                          'master';
                                      setState(() {
                                        _selectedInvoiceId = invoiceId;
                                        _billingMode = isRoomInvoice
                                            ? 'individual'
                                            : 'grouped';
                                        if (isRoomInvoice) {
                                          _selectedInvoiceIds.add(invoiceId);
                                        }
                                      });
                                      _fetchFolio(
                                        invoiceId: invoiceId,
                                        preserveBillingMode: isRoomInvoice
                                            ? 'individual'
                                            : 'grouped',
                                      );
                                    },
                              onToggleSelection: _isBusy
                                  ? null
                                  : (invoiceId, selected) {
                                      setState(() {
                                        if (selected) {
                                          _selectedInvoiceIds.add(invoiceId);
                                          _selectedInvoiceId = invoiceId;
                                          _billingMode = 'individual';
                                        } else {
                                          _selectedInvoiceIds.remove(invoiceId);
                                          if (_selectedInvoiceId == invoiceId) {
                                            _selectedInvoiceId =
                                                _selectedInvoiceIds.isNotEmpty
                                                ? _selectedInvoiceIds.first
                                                : _invoiceIdForBillingMode(
                                                    'individual',
                                                  );
                                          }
                                        }
                                      });
                                    },
                              onSelectAll: _isBusy
                                  ? null
                                  : () {
                                      setState(() {
                                        _billingMode = 'individual';
                                        _selectedInvoiceIds
                                          ..clear()
                                          ..addAll(
                                            _roomInvoices
                                                .map(
                                                  (invoice) =>
                                                      _asInt(invoice['id']),
                                                )
                                                .where((id) => id > 0),
                                          );
                                        _selectedInvoiceId =
                                            _roomInvoices.isNotEmpty
                                            ? _asInt(_roomInvoices.first['id'])
                                            : _selectedInvoiceId;
                                      });
                                    },
                              onClearSelection: _isBusy
                                  ? null
                                  : () {
                                      setState(
                                        () => _selectedInvoiceIds.clear(),
                                      );
                                    },
                            ),
                          ],
                          const SizedBox(height: 16),
                          if (widget.role != 'receptionist') ...[
                            _DiscountCard(
                              controller: _discountController,
                              mode: _discountMode,
                              onModeChanged: (mode) =>
                                  setState(() => _discountMode = mode),
                              onChanged: () => setState(() {}),
                            ),
                            const SizedBox(height: 16),
                          ],
                          _SectionHeader(
                            title: 'Lignes de facture',
                            action: _isFinalized
                                ? null
                                : TextButton.icon(
                                    onPressed: _isBusy ? null : _addExtra,
                                    icon: const Icon(Icons.add),
                                    label: const Text('Extra'),
                                  ),
                          ),
                          ...items.map(
                            (item) => _InvoiceItemTile(
                              item: Map<String, dynamic>.from(item as Map),
                            ),
                          ),
                        ],
                      ),
                    ),
                    VerticalDivider(color: Colors.grey.shade300),
                    Expanded(
                      flex: 1,
                      child: ListView(
                        padding: const EdgeInsets.all(16),
                        children: [
                          _SectionHeader(
                            title: 'Paiements',
                            action: _isFinalized
                                ? null
                                : TextButton.icon(
                                    onPressed: _isBusy ? null : _addPayment,
                                    icon: const Icon(Icons.add_card),
                                    label: const Text('Paiement'),
                                  ),
                          ),
                          if (!_isFinalized && !_canEditPayments)
                            const Padding(
                              padding: EdgeInsets.only(bottom: 8),
                              child: Text(
                                'Le réceptionniste ne peut modifier qu’un seul paiement par réservation.',
                                style: TextStyle(
                                  color: _muted,
                                  fontSize: 12,
                                  fontWeight: FontWeight.w600,
                                ),
                              ),
                            ),
                          if (payments.isEmpty)
                            const Padding(
                              padding: EdgeInsets.symmetric(vertical: 16),
                              child: Text('Aucun paiement enregistré.'),
                            )
                          else
                            ...payments.map(
                              (payment) => _PaymentTile(
                                payment: Map<String, dynamic>.from(
                                  payment as Map,
                                ),
                                onEdit: !_isFinalized && _canEditPayments
                                    ? () => _editPayment(
                                        Map<String, dynamic>.from(
                                          payment as Map,
                                        ),
                                      )
                                    : null,
                              ),
                            ),
                          const SizedBox(height: 20),
                          SegmentedButton<String>(
                            segments: const [
                              ButtonSegment(
                                value: 'facture',
                                label: Text('Facture'),
                                icon: Icon(Icons.description_outlined),
                              ),
                              ButtonSegment(
                                value: 'proforma',
                                label: Text('Proforma'),
                                icon: Icon(Icons.receipt_long_outlined),
                              ),
                            ],
                            selected: {_documentType},
                            onSelectionChanged: (selection) =>
                                setState(() => _documentType = selection.first),
                          ),
                          if (_isBookingReservation) ...[
                            const SizedBox(height: 12),
                            SwitchListTile(
                              value: _bookingInvoiceInEuro,
                              contentPadding: EdgeInsets.zero,
                              title: const Text('Facture Booking en euro'),
                              subtitle: const Text(
                                '32,50 EUR/chambre, options 10 EUR et 6 EUR',
                              ),
                              onChanged: _isBusy
                                  ? null
                                  : (value) => setState(
                                      () => _bookingInvoiceInEuro = value,
                                    ),
                            ),
                          ],
                          const SizedBox(height: 12),
                          ElevatedButton.icon(
                            onPressed: _isBusy ? null : _generatePdf,
                            icon: const Icon(Icons.picture_as_pdf_outlined),
                            label: Text(
                              _isOrganizationReservation &&
                                      _billingMode == 'individual'
                                  ? (_effectiveIndividualSelection.length > 1
                                        ? (_documentType == 'proforma'
                                              ? 'Mettre à jour les proformas PDF'
                                              : 'Mettre à jour les PDFs')
                                        : (_documentType == 'proforma'
                                              ? 'Mettre à jour le proforma PDF'
                                              : 'Mettre à jour le PDF'))
                                  : (_documentType == 'proforma'
                                        ? (_hasPdf
                                              ? 'Mettre à jour le proforma PDF'
                                              : 'Générer le proforma PDF')
                                        : (_hasPdf
                                              ? 'Mettre à jour le PDF'
                                              : 'Générer le PDF')),
                            ),
                          ),
                          const SizedBox(height: 10),
                          OutlinedButton.icon(
                            onPressed: _hasPdf ? _openPdfPreview : null,
                            icon: const Icon(Icons.visibility_outlined),
                            label: const Text('Visualiser'),
                          ),
                          OutlinedButton.icon(
                            onPressed: _hasPdf ? _downloadInvoice : null,
                            icon: const Icon(Icons.download_outlined),
                            label: const Text('Télécharger la facture'),
                          ),
                          OutlinedButton.icon(
                            onPressed: _hasPdf ? _sharePdf : null,
                            icon: const Icon(Icons.ios_share),
                            label: const Text('Partager'),
                          ),
                          OutlinedButton.icon(
                            onPressed: _hasPdf ? _printPdf : null,
                            icon: const Icon(Icons.print_outlined),
                            label: const Text('Imprimer'),
                          ),
                        ],
                      ),
                    ),
                  ],
                ),
                if (_isBusy)
                  Container(
                    color: Colors.black.withValues(alpha: 0.08),
                    child: const Center(child: CircularProgressIndicator()),
                  ),
              ],
            ),
    );
  }
}

String? _roomLabelForBooking(
  int bookingRoomId,
  List<Map<String, dynamic>> roomBookings,
) {
  for (final room in roomBookings) {
    final roomId = int.tryParse(room['id']?.toString() ?? '') ?? 0;
    if (roomId == bookingRoomId) {
      final roomNumber = room['room_number']?.toString() ?? '';
      final type = room['type']?.toString() ?? '';
      if (roomNumber.isEmpty) return type;
      if (type.isEmpty) return roomNumber;
      return '$roomNumber - $type';
    }
  }
  return null;
}

class _OrganizationInvoiceSelectionCard extends StatelessWidget {
  const _OrganizationInvoiceSelectionCard({
    required this.billingMode,
    required this.invoices,
    required this.individualEntries,
    required this.selectedInvoiceIds,
    required this.activeInvoiceId,
    required this.onSelectInvoice,
    required this.onToggleSelection,
    required this.onSelectAll,
    required this.onClearSelection,
  });

  final String billingMode;
  final List<Map<String, dynamic>> invoices;
  final List<Map<String, dynamic>> individualEntries;
  final Set<int> selectedInvoiceIds;
  final int? activeInvoiceId;
  final ValueChanged<int>? onSelectInvoice;
  final void Function(int invoiceId, bool selected)? onToggleSelection;
  final VoidCallback? onSelectAll;
  final VoidCallback? onClearSelection;

  @override
  Widget build(BuildContext context) {
    final roomInvoices = invoices
        .where(
          (invoice) =>
              (invoice['invoice_kind']?.toString() ?? 'master') != 'master',
        )
        .toList();
    final masterInvoice = invoices.firstWhere(
      (invoice) =>
          (invoice['invoice_kind']?.toString() ?? 'master') == 'master',
      orElse: () => const <String, dynamic>{},
    );
    final selectedCount = selectedInvoiceIds.length;
    final activeEntries = individualEntries;

    return Card(
      child: Padding(
        padding: const EdgeInsets.all(14),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Row(
              children: [
                const Expanded(
                  child: Text(
                    'Factures à générer',
                    style: TextStyle(
                      fontWeight: FontWeight.w900,
                      color: _ink,
                      fontSize: 16,
                    ),
                  ),
                ),
                if (billingMode == 'individual')
                  Text(
                    selectedCount == 0
                        ? '${activeEntries.length} sélectionnées par défaut'
                        : '$selectedCount sélectionnée${selectedCount > 1 ? 's' : ''}',
                    style: const TextStyle(
                      color: _muted,
                      fontWeight: FontWeight.w700,
                    ),
                  ),
              ],
            ),
            const SizedBox(height: 10),
            if (billingMode == 'grouped') ...[
              _InvoiceSelectionTile(
                title: 'Facture groupée',
                subtitle: _invoiceTileSubtitle(
                  masterInvoice,
                  roomInvoices.length,
                ),
                trailing: const Icon(Icons.groups_outlined, color: _primary),
                selected: true,
                active: activeInvoiceId == _asInt(masterInvoice['id']),
                onTap: onSelectInvoice == null
                    ? null
                    : () => onSelectInvoice!(_asInt(masterInvoice['id'])),
              ),
              const SizedBox(height: 8),
              const Text(
                'Le mode groupé génère une seule facture récapitulative.',
                style: TextStyle(color: _muted, fontWeight: FontWeight.w600),
              ),
            ] else ...[
              Row(
                children: [
                  TextButton.icon(
                    onPressed: onSelectAll,
                    icon: const Icon(Icons.select_all),
                    label: const Text('Tout sélectionner'),
                  ),
                  const SizedBox(width: 8),
                  TextButton.icon(
                    onPressed: onClearSelection,
                    icon: const Icon(Icons.deselect_outlined),
                    label: const Text('Tout désélectionner'),
                  ),
                ],
              ),
              const SizedBox(height: 4),
              if (activeEntries.isEmpty)
                const Padding(
                  padding: EdgeInsets.symmetric(vertical: 12),
                  child: Text(
                    'Aucune chambre disponible pour ce séjour.',
                    style: TextStyle(
                      color: _muted,
                      fontWeight: FontWeight.w600,
                    ),
                  ),
                )
              else
                ...activeEntries.map((entry) {
                  final invoiceId = _asInt(entry['id']);
                  final title = entry['title']?.toString() ?? 'Chambre';
                  final subtitle = entry['subtitle']?.toString() ?? '';
                  final isSelected = selectedInvoiceIds.contains(invoiceId);
                  return Padding(
                    padding: const EdgeInsets.only(bottom: 8),
                    child: _InvoiceSelectionTile(
                      title: title,
                      subtitle: subtitle,
                      trailing: Checkbox(
                        value: isSelected,
                        onChanged: onToggleSelection == null
                            ? null
                            : (value) =>
                                  onToggleSelection!(invoiceId, value ?? false),
                      ),
                      selected: isSelected,
                      active: billingMode == 'individual'
                          ? isSelected
                          : activeInvoiceId == invoiceId,
                      onTap: onSelectInvoice == null
                          ? null
                          : () {
                              if (billingMode == 'individual') {
                                onToggleSelection?.call(invoiceId, !isSelected);
                                return;
                              }
                              onSelectInvoice!(invoiceId);
                            },
                    ),
                  );
                }),
            ],
          ],
        ),
      ),
    );
  }
}

class _InvoiceSelectionTile extends StatelessWidget {
  const _InvoiceSelectionTile({
    required this.title,
    required this.subtitle,
    required this.trailing,
    required this.selected,
    required this.active,
    required this.onTap,
  });

  final String title;
  final String subtitle;
  final Widget trailing;
  final bool selected;
  final bool active;
  final VoidCallback? onTap;

  @override
  Widget build(BuildContext context) {
    return InkWell(
      onTap: onTap,
      borderRadius: BorderRadius.circular(12),
      child: Container(
        decoration: BoxDecoration(
          color: active ? const Color(0xFFF0FDFA) : Colors.white,
          borderRadius: BorderRadius.circular(12),
          border: Border.all(color: active ? _primary : _border),
        ),
        padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 10),
        child: Row(
          children: [
            Expanded(
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  Row(
                    children: [
                      Expanded(
                        child: Text(
                          title,
                          style: const TextStyle(
                            color: _ink,
                            fontWeight: FontWeight.w900,
                          ),
                        ),
                      ),
                      if (selected)
                        const Padding(
                          padding: EdgeInsets.only(left: 8),
                          child: Icon(
                            Icons.check_circle,
                            size: 18,
                            color: _primary,
                          ),
                        ),
                    ],
                  ),
                  const SizedBox(height: 4),
                  Text(
                    subtitle,
                    style: const TextStyle(
                      color: _muted,
                      fontWeight: FontWeight.w600,
                    ),
                  ),
                ],
              ),
            ),
            const SizedBox(width: 12),
            trailing,
          ],
        ),
      ),
    );
  }
}

String _invoiceTileSubtitle(Map<String, dynamic> invoice, int? roomCount) {
  final invoiceNumber = invoice['invoice_number']?.toString() ?? '';
  final total = _asInt(invoice['total_amount_ariary']);
  final amountText = total > 0
      ? '${formatPrice(total)} Ar'
      : 'Montant indisponible';
  final parts = <String>[
    if (invoiceNumber.isNotEmpty) invoiceNumber,
    amountText,
    if (roomCount != null) '$roomCount chambre${roomCount > 1 ? 's' : ''}',
  ];
  return parts.join(' • ');
}

class _DiscountCard extends StatelessWidget {
  const _DiscountCard({
    required this.controller,
    required this.mode,
    required this.onModeChanged,
    required this.onChanged,
  });

  final TextEditingController controller;
  final String mode;
  final ValueChanged<String> onModeChanged;
  final VoidCallback onChanged;

  @override
  Widget build(BuildContext context) {
    return Card(
      child: Padding(
        padding: const EdgeInsets.all(14),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            const Text(
              'Remise',
              style: TextStyle(fontWeight: FontWeight.w900, color: _ink),
            ),
            const SizedBox(height: 10),
            Row(
              children: [
                Expanded(
                  child: SegmentedButton<String>(
                    segments: const [
                      ButtonSegment(
                        value: 'amount',
                        label: Text('Ar'),
                        icon: Icon(Icons.payments_outlined),
                      ),
                      ButtonSegment(
                        value: 'percent',
                        label: Text('%'),
                        icon: Icon(Icons.percent),
                      ),
                    ],
                    selected: {mode},
                    onSelectionChanged: (selection) =>
                        onModeChanged(selection.first),
                  ),
                ),
              ],
            ),
            const SizedBox(height: 10),
            TextField(
              controller: controller,
              keyboardType: TextInputType.number,
              onChanged: (_) => onChanged(),
              decoration: InputDecoration(
                labelText: mode == 'percent'
                    ? 'Remise en %'
                    : 'Remise en Ariary',
                prefixIcon: const Icon(Icons.discount_outlined),
              ),
            ),
          ],
        ),
      ),
    );
  }
}

class InvoicePdfPage extends StatelessWidget {
  const InvoicePdfPage({super.key, required this.title, required this.bytes});

  final String title;
  final Uint8List bytes;

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: AppBar(title: Text(title)),
      body: PdfPreview(
        canChangeOrientation: false,
        canChangePageFormat: false,
        allowSharing: false,
        allowPrinting: false,
        build: (_) async => bytes,
      ),
    );
  }
}

class _SummaryPanel extends StatelessWidget {
  const _SummaryPanel({
    required this.folio,
    required this.reservation,
    required this.showLoyalty,
  });

  final Map<String, dynamic> folio;
  final Map<String, dynamic> reservation;
  final bool showLoyalty;

  @override
  Widget build(BuildContext context) {
    final status = folio['status']?.toString() ?? 'open';
    final guest = folio['guest'];
    final bookingType =
        (folio['booking_type'] ?? reservation['booking_type'] ?? 'individual')
            .toString();
    final organization = reservation['organization'];
    final organizationName = organization is Map
        ? (organization['name'] ?? '').toString().trim()
        : '';
    final loyaltyCount = guest is Map ? _asInt(guest['loyalty_count']) : 0;
    final guestName = guest is Map
        ? (guest['full_name'] ?? guest['first_name'] ?? '').toString().trim()
        : '';
    final clientName =
        bookingType == 'organization' && organizationName.isNotEmpty
        ? organizationName
        : guestName;
    final hasLoyaltyInfo = guest is Map;
    final contact = _formatContact(reservation);
    final depositAmount = _asInt(folio['deposit_amount_ariary']);
    final paidAmount = _asInt(folio['paid_amount_ariary']);
    final balanceAmount = _asInt(folio['balance_amount_ariary']);
    final changeAmount = _asInt(folio['change_given_ariary']);

    return Container(
      padding: const EdgeInsets.all(14),
      decoration: BoxDecoration(
        color: Colors.white,
        borderRadius: BorderRadius.circular(8),
        border: Border.all(color: _border),
      ),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Row(
            children: [
              const Expanded(
                child: Text(
                  'Note de chambre',
                  style: TextStyle(
                    color: _ink,
                    fontWeight: FontWeight.w900,
                    fontSize: 18,
                  ),
                ),
              ),
              _StatusChip(status: status),
            ],
          ),
          const SizedBox(height: 8),
          if (showLoyalty) ...[
            Container(
              width: double.infinity,
              padding: const EdgeInsets.all(12),
              decoration: BoxDecoration(
                color: hasLoyaltyInfo
                    ? const Color(0xFFE6FFFB)
                    : const Color(0xFFF8FAFC),
                borderRadius: BorderRadius.circular(10),
                border: Border.all(color: hasLoyaltyInfo ? _primary : _border),
              ),
              child: Text(
                hasLoyaltyInfo
                    ? 'Client régulier : $loyaltyCount visite${loyaltyCount > 1 ? 's' : ''}${guestName.isNotEmpty ? ' - $guestName' : ''}'
                    : 'Fidélité client : information indisponible',
                style: const TextStyle(
                  fontWeight: FontWeight.w800,
                  color: _primaryDark,
                ),
              ),
            ),
            const SizedBox(height: 12),
          ],
          if (bookingType == 'organization' && clientName.isNotEmpty) ...[
            Text(
              'Client : $clientName',
              style: const TextStyle(color: _ink, fontWeight: FontWeight.w800),
            ),
            const SizedBox(height: 8),
          ],
          if (contact.isNotEmpty) ...[
            Text(
              'Contact : $contact',
              style: const TextStyle(
                color: _muted,
                fontWeight: FontWeight.w700,
              ),
            ),
            const SizedBox(height: 12),
          ],
          Wrap(
            spacing: 16,
            runSpacing: 12,
            children: [
              _AmountMetric(
                label: 'Total',
                value: _asPrice(folio['total_amount_ariary']),
              ),
              _AmountMetric(label: 'Acompte', value: _asPrice(depositAmount)),
              _AmountMetric(label: 'Total payé', value: _asPrice(paidAmount)),
              _AmountMetric(
                label: 'Reste à payer',
                value: _asPrice(balanceAmount),
                emphasized: true,
              ),
              _AmountMetric(
                label: 'Monnaie rendue',
                value: _asPrice(changeAmount),
              ),
            ],
          ),
        ],
      ),
    );
  }

  static String _asPrice(dynamic value) => '${formatPrice(_asInt(value))} Ar';

  static String _formatContact(Map<String, dynamic> reservation) {
    final phone =
        (reservation['phone'] ??
                reservation['customer_phone'] ??
                reservation['client_phone'] ??
                '')
            .toString()
            .trim();
    final email = (reservation['email'] ?? reservation['customer_email'] ?? '')
        .toString()
        .trim();

    final parts = <String>[];
    if (phone.isNotEmpty && phone != 'N/A') {
      parts.add(phone);
    }
    if (email.isNotEmpty && email != 'N/A') {
      parts.add(email);
    }
    return parts.join(' | ');
  }
}

class _AmountMetric extends StatelessWidget {
  const _AmountMetric({
    required this.label,
    required this.value,
    this.emphasized = false,
  });

  final String label;
  final String value;
  final bool emphasized;

  @override
  Widget build(BuildContext context) {
    return SizedBox(
      width: 130,
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Text(
            label,
            style: const TextStyle(color: _muted, fontWeight: FontWeight.w700),
          ),
          Text(
            value,
            style: TextStyle(
              color: emphasized ? _primaryDark : _ink,
              fontWeight: FontWeight.w900,
              fontSize: emphasized ? 18 : 15,
            ),
          ),
        ],
      ),
    );
  }
}

class _SectionHeader extends StatelessWidget {
  const _SectionHeader({required this.title, this.action});

  final String title;
  final Widget? action;

  @override
  Widget build(BuildContext context) {
    return Row(
      children: [
        Expanded(
          child: Text(
            title,
            style: const TextStyle(
              color: _ink,
              fontWeight: FontWeight.w900,
              fontSize: 16,
            ),
          ),
        ),
        ?action,
      ],
    );
  }
}

class _InvoiceItemTile extends StatelessWidget {
  const _InvoiceItemTile({required this.item});

  final Map<String, dynamic> item;

  @override
  Widget build(BuildContext context) {
    return Card(
      child: ListTile(
        leading: const Icon(Icons.bed_outlined, color: _primary),
        title: Text(
          item['description']?.toString() ?? '',
          style: const TextStyle(fontWeight: FontWeight.w800),
        ),
        subtitle: Text(
          'Qté ${item['quantity']} x ${formatPrice(_asInt(item['amount_ariary']))} Ar',
        ),
        trailing: Text(
          '${formatPrice(_asInt(item['line_total_ariary']))} Ar',
          style: const TextStyle(fontWeight: FontWeight.w900),
        ),
      ),
    );
  }
}

class _PaymentTile extends StatelessWidget {
  const _PaymentTile({required this.payment, this.onEdit});

  final Map<String, dynamic> payment;
  final VoidCallback? onEdit;

  @override
  Widget build(BuildContext context) {
    final contextLabel =
        (payment['payment_context']?.toString() ?? 'payment') == 'deposit'
        ? 'Acompte'
        : 'Paiement';
    final received = _asInt(
      payment['amount_received_ariary'] ?? payment['amount_ariary'],
    );
    final applied = _asInt(payment['amount_ariary']);
    final change = _asInt(payment['change_given_ariary']);
    return Card(
      child: ListTile(
        leading: const Icon(Icons.payments_outlined, color: _primary),
        title: Text(
          'Reçu : ${formatPrice(received)} Ar',
          style: const TextStyle(fontWeight: FontWeight.w900),
        ),
        subtitle: Text(
          [
            contextLabel,
            payment['payment_method']?.toString(),
            payment['payment_method']?.toString() == 'Mobile Money' &&
                    (payment['payment_operator'] ?? '').toString().isNotEmpty
                ? payment['payment_operator'].toString()
                : null,
            'Net : ${formatPrice(applied)} Ar',
            if (change > 0) 'Rendu : ${formatPrice(change)} Ar',
            [
              payment['processed_by_name']?.toString(),
              payment['processed_by_role']?.toString(),
            ].where((value) => value != null && value.isNotEmpty).join(' / '),
            payment['reference']?.toString(),
          ].where((value) => value != null && value.isNotEmpty).join(' - '),
        ),
        trailing: onEdit == null
            ? null
            : IconButton(
                tooltip: 'Modifier',
                icon: const Icon(Icons.edit_outlined),
                onPressed: onEdit,
              ),
      ),
    );
  }
}

class _StatusChip extends StatelessWidget {
  const _StatusChip({required this.status});

  final String status;

  @override
  Widget build(BuildContext context) {
    final label = switch (status) {
      'partial' => 'Partiel',
      'paid' => 'Réglé',
      'finalized' => 'Finalisé',
      _ => 'Ouvert',
    };
    final color = switch (status) {
      'paid' || 'finalized' => const Color(0xFF047857),
      'partial' => const Color(0xFFB45309),
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

int _asInt(dynamic value) {
  if (value is int) return value;
  if (value is num) return value.toInt();
  return int.tryParse(value?.toString() ?? '') ?? 0;
}
