import 'dart:convert';
import 'dart:typed_data';

import 'package:flutter/material.dart';
import 'package:pdf/pdf.dart';
import 'package:pdf/widgets.dart' as pw;
import 'package:printing/printing.dart';
import 'package:share_plus/share_plus.dart';
import 'package:shared_preferences/shared_preferences.dart';

import '../core/formatters.dart';
import '../services/api_client.dart';
import '../services/pdf_download.dart';

const Color _ink = Color(0xFF0F172A);
const Color _muted = Color(0xFF64748B);
const Color _border = Color(0xFFE2E8F0);
const Color _sand = Color(0xFFF8FAFC);

String _shortDate(DateTime? dateTime) {
  if (dateTime == null) return 'N/A';
  final local = dateTime.toLocal();
  final day = local.day.toString().padLeft(2, '0');
  final month = local.month.toString().padLeft(2, '0');
  return '$day/$month/${local.year}';
}

class PaymentSummaryPage extends StatefulWidget {
  const PaymentSummaryPage({
    super.key,
    required this.role,
    required this.userName,
  });

  final String role;
  final String userName;

  @override
  State<PaymentSummaryPage> createState() => _PaymentSummaryPageState();
}

class _PaymentSummaryPageState extends State<PaymentSummaryPage> {
  final _apiClient = const ApiClient();
  bool _isLoading = true;
  String _errorMessage = '';
  String _viewMode = 'paid';
  String _selectedMethod = 'Tous';
  String _selectedProcessor = 'Tous';
  DateTime _selectedStartDate = DateTime.now();
  DateTime _selectedEndDate = DateTime.now();
  List<_SummaryReservation> _reservations = [];

  @override
  void initState() {
    super.initState();
    final today = DateTime.now();
    _selectedEndDate = today;
    _selectedStartDate = today.subtract(const Duration(days: 1));
    _fetchReservations();
  }

  DateTime get _normalizedStartDate => DateTime(
    _selectedStartDate.year,
    _selectedStartDate.month,
    _selectedStartDate.day,
  );

  DateTime get _normalizedEndDate => DateTime(
    _selectedEndDate.year,
    _selectedEndDate.month,
    _selectedEndDate.day,
  );

  String get _dateKey =>
      '${_normalizedStartDate.toIso8601String().substring(0, 10)}_${_normalizedEndDate.toIso8601String().substring(0, 10)}';

  String get _dateRangeLabel =>
      'du ${_formatDate(_normalizedStartDate)} au ${_formatDate(_normalizedEndDate)}';

  bool _isWithinRange(DateTime date) {
    final target = DateTime(date.year, date.month, date.day);
    return !target.isBefore(_normalizedStartDate) &&
        !target.isAfter(_normalizedEndDate);
  }

  String _formatDateTime(DateTime? dateTime) {
    if (dateTime == null) return 'N/A';
    final local = dateTime.toLocal();
    final hh = local.hour.toString().padLeft(2, '0');
    final mm = local.minute.toString().padLeft(2, '0');
    return '${_shortDate(local)} $hh:$mm';
  }

  String _formatDate(DateTime? dateTime) {
    return _shortDate(dateTime);
  }

  String _formatRange(DateTime start, DateTime end) {
    return '${_formatDate(start)} - ${_formatDate(end)}';
  }

  Future<void> _fetchReservations() async {
    if (mounted) {
      setState(() {
        _isLoading = true;
        _errorMessage = '';
      });
    }

    const cacheKey = 'payment_summary:reservations_all';
    try {
      final response = await _apiClient.get('/api/reservations/all', {
        'date': 'all',
        'status': 'all',
      }, const Duration(seconds: 8));
      if (!mounted) return;
      if (response.statusCode == 200) {
        final decoded = json.decode(response.body);
        final data = decoded is List ? decoded : const [];
        final reservations = data
            .whereType<Map>()
            .map(
              (item) =>
                  _SummaryReservation.fromJson(Map<String, dynamic>.from(item)),
            )
            .toList();
        setState(() {
          _reservations = reservations;
        });
        final prefs = await SharedPreferences.getInstance();
        await prefs.setString(cacheKey, json.encode(data));
      } else {
        throw Exception('Erreur ${response.statusCode}');
      }
    } catch (e) {
      final prefs = await SharedPreferences.getInstance();
      final cached = prefs.getString(cacheKey);
      if (cached != null) {
        final decoded = json.decode(cached);
        final data = decoded is List ? decoded : const [];
        if (!mounted) return;
        setState(() {
          _reservations = data
              .whereType<Map>()
              .map(
                (item) => _SummaryReservation.fromJson(
                  Map<String, dynamic>.from(item),
                ),
              )
              .toList();
          _errorMessage = 'Mode hors ligne : récapitulatif local affiché.';
        });
      } else if (mounted) {
        setState(() {
          _errorMessage = 'Impossible de charger le récapitulatif.';
        });
      }
    } finally {
      if (mounted) {
        setState(() => _isLoading = false);
      }
    }
  }

  List<_SummaryReservation> get _activeReservations =>
      _reservations
          .where(
            (reservation) =>
                !reservation.isCancelled &&
                reservation.checkIn != null &&
                reservation.checkOut != null &&
                !reservation.checkIn!.isAfter(_normalizedEndDate) &&
                !reservation.checkOut!.isBefore(_normalizedStartDate),
          )
          .toList()
        ..sort((a, b) {
          final byDate = a.checkIn!.compareTo(b.checkIn!);
          if (byDate != 0) return byDate;
          return a.clientName.compareTo(b.clientName);
        });

  List<_SummaryPayment> get _paymentsForRange {
    return _reservations
        .where((reservation) => !reservation.isCancelled)
        .expand(
          (reservation) => reservation.payments.map((payment) {
            return payment.copyWithReservation(reservation);
          }),
        )
        .where(
          (payment) =>
              payment.createdAt != null && _isWithinRange(payment.createdAt!),
        )
        .toList()
      ..sort((a, b) {
        final dateA = a.createdAt ?? DateTime.fromMillisecondsSinceEpoch(0);
        final dateB = b.createdAt ?? DateTime.fromMillisecondsSinceEpoch(0);
        return dateA.compareTo(dateB);
      });
  }

  List<_SummaryPayment> get _visiblePayments {
    return _paymentsForRange
        .where(
          (payment) =>
              _selectedMethod == 'Tous' ||
              payment.displayMethod == _selectedMethod,
        )
        .where(
          (payment) =>
              _selectedProcessor == 'Tous' ||
              payment.processedBy == _selectedProcessor,
        )
        .toList();
  }

  Set<String> get _availablePaymentMethods {
    final methods =
        _paymentsForRange
            .map((payment) => payment.displayMethod)
            .where((method) => method.isNotEmpty)
            .toSet()
          ..remove('N/A');
    return methods;
  }

  Set<String> get _availableProcessors {
    final processors = <String>{
      ..._paymentsForRange
          .map((payment) => payment.processedBy)
          .where((value) => value.isNotEmpty && value != 'N/A'),
      ..._pendingReservations
          .map((reservation) => reservation.latestProcessedBy)
          .where((value) => value.isNotEmpty && value != 'N/A'),
    };
    return processors;
  }

  List<_SummaryReservation> get _pendingReservations {
    return _activeReservations
        .where(
          (reservation) =>
              reservation.paymentStatus != 'paid' ||
              reservation.balanceAmount > 0 ||
              reservation.status == 'en_attente',
        )
        .toList()
      ..sort((a, b) {
        final balance = b.balanceAmount.compareTo(a.balanceAmount);
        if (balance != 0) return balance;
        return a.clientName.compareTo(b.clientName);
      });
  }

  List<_SummaryReservation> get _visiblePendingReservations {
    return _pendingReservations
        .where(
          (reservation) =>
              _selectedProcessor == 'Tous' ||
              reservation.latestProcessedBy == _selectedProcessor,
        )
        .toList();
  }

  int get _totalPayments => _visiblePayments.fold<int>(
    0,
    (sum, payment) => sum + payment.amountReceived,
  );

  int get _totalPending => _visiblePendingReservations.fold<int>(
    0,
    (sum, reservation) => sum + reservation.balanceAmount,
  );

  Future<Uint8List> _buildPdfBytes() async {
    final doc = pw.Document();
    final isPaidMode = _viewMode == 'paid';
    final title = isPaidMode
        ? 'Récapitulatif des paiements'
        : 'Récapitulatif des impayés';
    final subtitle = isPaidMode
        ? 'Paiements encaissés $_dateRangeLabel'
        : 'Réservations impayées $_dateRangeLabel';

    final bodyWidgets = <pw.Widget>[
      pw.Text(
        'Kamoro Hotel',
        style: pw.TextStyle(fontSize: 16, fontWeight: pw.FontWeight.bold),
      ),
      pw.SizedBox(height: 3),
      pw.Text(
        title,
        style: pw.TextStyle(fontSize: 13, fontWeight: pw.FontWeight.bold),
      ),
      pw.Text(
        subtitle,
        style: const pw.TextStyle(fontSize: 9, color: PdfColors.grey700),
      ),
      pw.SizedBox(height: 8),
      pw.Text(
        'Exclut les réservations annulées.',
        style: const pw.TextStyle(fontSize: 8.5, color: PdfColors.grey600),
      ),
      pw.SizedBox(height: 8),
    ];

    if (isPaidMode) {
      bodyWidgets.addAll([
        _buildPdfStatsRow([
          _PdfStat('Paiements', _visiblePayments.length.toString()),
          _PdfStat('Montant total', formatPrice(_totalPayments)),
          _PdfStat(
            'Réservations',
            _visiblePayments
                .map((payment) => payment.reference)
                .where((value) => value.isNotEmpty)
                .toSet()
                .length
                .toString(),
          ),
        ]),
        pw.SizedBox(height: 10),
        _buildPaymentsPdfTable(visiblePayments: _visiblePayments),
      ]);
    } else {
      bodyWidgets.addAll([
        _buildPdfStatsRow([
          _PdfStat(
            'Réservations',
            _visiblePendingReservations.length.toString(),
          ),
          _PdfStat('Reste à payer', formatPrice(_totalPending)),
          _PdfStat(
            'En attente',
            _visiblePendingReservations
                .where((reservation) => reservation.paymentStatus != 'paid')
                .length
                .toString(),
          ),
        ]),
        pw.SizedBox(height: 10),
        _buildPendingPdfTable(
          visiblePendingReservations: _visiblePendingReservations,
        ),
      ]);
    }

    doc.addPage(
      pw.MultiPage(
        pageFormat: PdfPageFormat.a4,
        margin: const pw.EdgeInsets.fromLTRB(16, 16, 16, 18),
        build: (_) => bodyWidgets,
      ),
    );

    return doc.save();
  }

  pw.Widget _buildPdfStatsRow(List<_PdfStat> stats) {
    return pw.Row(
      children: stats
          .map(
            (stat) => pw.Expanded(
              child: pw.Container(
                margin: const pw.EdgeInsets.only(right: 6),
                padding: const pw.EdgeInsets.symmetric(
                  horizontal: 8,
                  vertical: 6,
                ),
                decoration: pw.BoxDecoration(
                  border: pw.Border.all(color: PdfColors.grey300),
                  borderRadius: pw.BorderRadius.circular(4),
                ),
                child: pw.Column(
                  crossAxisAlignment: pw.CrossAxisAlignment.start,
                  children: [
                    pw.Text(
                      stat.label,
                      style: const pw.TextStyle(
                        fontSize: 8,
                        color: PdfColors.grey700,
                      ),
                    ),
                    pw.SizedBox(height: 2),
                    pw.Text(
                      stat.value,
                      style: pw.TextStyle(
                        fontSize: 10,
                        fontWeight: pw.FontWeight.bold,
                      ),
                    ),
                  ],
                ),
              ),
            ),
          )
          .toList(),
    );
  }

  pw.Widget _buildPaymentsPdfTable({
    required List<_SummaryPayment> visiblePayments,
  }) {
    final data = visiblePayments
        .map(
          (payment) => [
            payment.reference,
            payment.clientName,
            payment.rooms,
            payment.stayLabel,
            payment.displayMethod,
            payment.processedBy,
            payment.paymentTypeLabel,
            formatPrice(payment.amountReceived),
            _formatDateTime(payment.createdAt),
          ],
        )
        .toList();

    return pw.TableHelper.fromTextArray(
      headers: const [
        'Réservation',
        'Client',
        'Chambres',
        'Date séjour',
        'Mode',
        'Pris par',
        'Type',
        'Montant',
        'Date',
      ],
      data: data,
      headerStyle: pw.TextStyle(fontSize: 8.5, fontWeight: pw.FontWeight.bold),
      cellStyle: const pw.TextStyle(fontSize: 8),
      headerDecoration: const pw.BoxDecoration(color: PdfColors.grey200),
      border: pw.TableBorder.all(color: PdfColors.grey300, width: 0.4),
      cellPadding: const pw.EdgeInsets.symmetric(horizontal: 4, vertical: 3),
      columnWidths: const {
        0: pw.FlexColumnWidth(1.0),
        1: pw.FlexColumnWidth(1.4),
        2: pw.FlexColumnWidth(1.1),
        3: pw.FlexColumnWidth(1.15),
        4: pw.FlexColumnWidth(1.15),
        5: pw.FlexColumnWidth(1.25),
        6: pw.FlexColumnWidth(0.9),
        7: pw.FlexColumnWidth(0.95),
        8: pw.FlexColumnWidth(1.0),
      },
    );
  }

  pw.Widget _buildPendingPdfTable({
    required List<_SummaryReservation> visiblePendingReservations,
  }) {
    final data = visiblePendingReservations
        .map(
          (reservation) => [
            reservation.reference,
            reservation.clientName,
            reservation.rooms,
            reservation.stayLabel,
            reservation.paymentStatusLabel,
            formatPrice(reservation.totalAmount),
            formatPrice(reservation.paidAmount),
            formatPrice(reservation.balanceAmount),
            reservation.latestPaymentMethod,
            reservation.latestProcessedBy,
          ],
        )
        .toList();

    return pw.TableHelper.fromTextArray(
      headers: const [
        'Réservation',
        'Client',
        'Chambres',
        'Date séjour',
        'Statut',
        'Total',
        'Payé',
        'Solde',
        'Dernier mode',
        'Pris par',
      ],
      data: data,
      headerStyle: pw.TextStyle(fontSize: 8.5, fontWeight: pw.FontWeight.bold),
      cellStyle: const pw.TextStyle(fontSize: 8),
      headerDecoration: const pw.BoxDecoration(color: PdfColors.grey200),
      border: pw.TableBorder.all(color: PdfColors.grey300, width: 0.4),
      cellPadding: const pw.EdgeInsets.symmetric(horizontal: 4, vertical: 3),
      columnWidths: const {
        0: pw.FlexColumnWidth(0.9),
        1: pw.FlexColumnWidth(1.2),
        2: pw.FlexColumnWidth(1.05),
        3: pw.FlexColumnWidth(1.15),
        4: pw.FlexColumnWidth(0.9),
        5: pw.FlexColumnWidth(0.95),
        6: pw.FlexColumnWidth(0.95),
        7: pw.FlexColumnWidth(0.95),
        8: pw.FlexColumnWidth(1.0),
        9: pw.FlexColumnWidth(1.1),
      },
    );
  }

  Future<void> _openPreview() async {
    final bytes = await _buildPdfBytes();
    if (!mounted) return;
    await Navigator.push(
      context,
      MaterialPageRoute(
        builder: (_) => Scaffold(
          appBar: AppBar(title: const Text('Apercu du rapport')),
          body: PdfPreview(
            build: (_) async => bytes,
            allowPrinting: false,
            allowSharing: false,
            canChangeOrientation: false,
            canChangePageFormat: false,
            pdfFileName: _pdfFileName(),
          ),
        ),
      ),
    );
  }

  Future<void> _downloadPdf() async {
    final bytes = await _buildPdfBytes();
    if (!mounted) return;
    final message = await savePdfToDownloads(bytes, _pdfFileName());
    if (!mounted) return;
    ScaffoldMessenger.of(
      context,
    ).showSnackBar(SnackBar(content: Text(message)));
  }

  Future<void> _sharePdf() async {
    final bytes = await _buildPdfBytes();
    if (!mounted) return;
    final filename = _pdfFileName();
    await SharePlus.instance.share(
      ShareParams(
        files: [
          XFile.fromData(bytes, name: filename, mimeType: 'application/pdf'),
        ],
        text: filename,
      ),
    );
  }

  Future<void> _printPdf() async {
    final bytes = await _buildPdfBytes();
    await Printing.layoutPdf(onLayout: (_) async => bytes);
  }

  String _pdfFileName() {
    final suffix = _viewMode == 'paid' ? 'paiements' : 'impayes';
    return 'recap-$suffix-$_dateKey.pdf';
  }

  void _setViewMode(String value) {
    setState(() {
      _viewMode = value;
      _selectedMethod = 'Tous';
      _selectedProcessor = 'Tous';
    });
  }

  @override
  Widget build(BuildContext context) {
    final isPaidMode = _viewMode == 'paid';
    final visiblePayments = _visiblePayments;
    final visiblePending = _visiblePendingReservations;

    return Scaffold(
      appBar: AppBar(
        title: const Text('Récapitulatif'),
        actions: [
          IconButton(
            tooltip: 'Rafraichir',
            onPressed: _isLoading ? null : _fetchReservations,
            icon: const Icon(Icons.refresh),
          ),
        ],
      ),
      body: Container(
        color: _sand,
        child: SafeArea(
          child: _isLoading
              ? const Center(child: CircularProgressIndicator())
              : Padding(
                  padding: const EdgeInsets.all(16),
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.stretch,
                    children: [
                      if (_errorMessage.isNotEmpty)
                        Padding(
                          padding: const EdgeInsets.only(bottom: 12),
                          child: Text(
                            _errorMessage,
                            style: const TextStyle(
                              color: _muted,
                              fontWeight: FontWeight.w700,
                            ),
                          ),
                        ),
                      Wrap(
                        spacing: 12,
                        runSpacing: 12,
                        crossAxisAlignment: WrapCrossAlignment.center,
                        children: [
                          OutlinedButton.icon(
                            onPressed: () async {
                              final picked = await showDateRangePicker(
                                context: context,
                                firstDate: DateTime.now().subtract(
                                  const Duration(days: 1825),
                                ),
                                lastDate: DateTime.now().add(
                                  const Duration(days: 365),
                                ),
                                initialDateRange: DateTimeRange(
                                  start: _selectedStartDate,
                                  end: _selectedEndDate,
                                ),
                              );
                              if (picked != null) {
                                setState(() {
                                  _selectedStartDate = picked.start;
                                  _selectedEndDate = picked.end;
                                  _selectedMethod = 'Tous';
                                  _selectedProcessor = 'Tous';
                                });
                              }
                            },
                            icon: const Icon(Icons.calendar_today_outlined),
                            label: Text(
                              'Période ${_formatRange(_selectedStartDate, _selectedEndDate)}',
                            ),
                          ),
                          SegmentedButton<String>(
                            segments: const [
                              ButtonSegment(
                                value: 'paid',
                                label: Text('Paiement'),
                                icon: Icon(Icons.payments_outlined),
                              ),
                              ButtonSegment(
                                value: 'pending',
                                label: Text('Impayé'),
                                icon: Icon(Icons.hourglass_bottom),
                              ),
                            ],
                            selected: {_viewMode},
                            onSelectionChanged: (selection) =>
                                _setViewMode(selection.first),
                          ),
                          if (isPaidMode && _availablePaymentMethods.isNotEmpty)
                            Wrap(
                              spacing: 8,
                              runSpacing: 8,
                              children: [
                                ChoiceChip(
                                  label: const Text('Tous'),
                                  selected: _selectedMethod == 'Tous',
                                  onSelected: (_) =>
                                      setState(() => _selectedMethod = 'Tous'),
                                ),
                                ..._availablePaymentMethods.map(
                                  (method) => ChoiceChip(
                                    label: Text(method),
                                    selected: _selectedMethod == method,
                                    onSelected: (_) => setState(
                                      () => _selectedMethod = method,
                                    ),
                                  ),
                                ),
                              ],
                            ),
                          if (_availableProcessors.isNotEmpty)
                            Wrap(
                              spacing: 8,
                              runSpacing: 8,
                              children: [
                                ChoiceChip(
                                  label: const Text('Tous les traitants'),
                                  selected: _selectedProcessor == 'Tous',
                                  onSelected: (_) => setState(
                                    () => _selectedProcessor = 'Tous',
                                  ),
                                ),
                                ..._availableProcessors.map(
                                  (processor) => ChoiceChip(
                                    label: Text(processor),
                                    selected: _selectedProcessor == processor,
                                    onSelected: (_) => setState(
                                      () => _selectedProcessor = processor,
                                    ),
                                  ),
                                ),
                              ],
                            ),
                        ],
                      ),
                      const SizedBox(height: 12),
                      Wrap(
                        spacing: 12,
                        runSpacing: 12,
                        children: [
                          _SummaryChip(
                            label: isPaidMode ? 'Paiements' : 'Réservations',
                            value: isPaidMode
                                ? visiblePayments.length.toString()
                                : visiblePending.length.toString(),
                          ),
                          _SummaryChip(
                            label: isPaidMode
                                ? 'Montant total'
                                : 'Reste à payer',
                            value: isPaidMode
                                ? formatPrice(
                                    visiblePayments.fold<int>(
                                      0,
                                      (sum, payment) =>
                                          sum + payment.amountReceived,
                                    ),
                                  )
                                : formatPrice(_totalPending),
                          ),
                          _SummaryChip(
                            label: isPaidMode ? 'Réservations' : 'En attente',
                            value: isPaidMode
                                ? visiblePayments
                                      .map((payment) => payment.reference)
                                      .where((value) => value.isNotEmpty)
                                      .toSet()
                                      .length
                                      .toString()
                                : visiblePending.length.toString(),
                          ),
                        ],
                      ),
                      const SizedBox(height: 12),
                      Wrap(
                        spacing: 12,
                        runSpacing: 12,
                        children: [
                          OutlinedButton.icon(
                            onPressed: _openPreview,
                            icon: const Icon(Icons.visibility_outlined),
                            label: const Text('Visualiser'),
                          ),
                          OutlinedButton.icon(
                            onPressed: _downloadPdf,
                            icon: const Icon(Icons.download_outlined),
                            label: const Text('Télécharger'),
                          ),
                          OutlinedButton.icon(
                            onPressed: _sharePdf,
                            icon: const Icon(Icons.ios_share),
                            label: const Text('Partager'),
                          ),
                          OutlinedButton.icon(
                            onPressed: _printPdf,
                            icon: const Icon(Icons.print_outlined),
                            label: const Text('Imprimer'),
                          ),
                        ],
                      ),
                      const SizedBox(height: 12),
                      Expanded(
                        child: Container(
                          decoration: BoxDecoration(
                            color: Colors.white,
                            border: Border.all(color: _border),
                            borderRadius: BorderRadius.circular(12),
                          ),
                          child: isPaidMode
                              ? _buildPaymentsTable(visiblePayments)
                              : _buildPendingTable(visiblePending),
                        ),
                      ),
                    ],
                  ),
                ),
        ),
      ),
    );
  }

  Widget _buildPaymentsTable(List<_SummaryPayment> rows) {
    if (rows.isEmpty) {
      return const Center(child: Text('Aucun paiement pour cette période.'));
    }

    return SingleChildScrollView(
      padding: const EdgeInsets.all(12),
      child: SingleChildScrollView(
        scrollDirection: Axis.horizontal,
        child: DataTable(
          columnSpacing: 20,
          dataRowMinHeight: 44,
          headingRowHeight: 42,
          columns: const [
            DataColumn(label: Text('Réservation')),
            DataColumn(label: Text('Client')),
            DataColumn(label: Text('Chambres')),
            DataColumn(label: Text('Date séjour')),
            DataColumn(label: Text('Mode')),
            DataColumn(label: Text('Pris par')),
            DataColumn(label: Text('Type')),
            DataColumn(label: Text('Montant')),
            DataColumn(label: Text('Date')),
          ],
          rows: rows.map((payment) {
            return DataRow(
              cells: [
                DataCell(Text(payment.reference)),
                DataCell(Text(payment.clientName)),
                DataCell(Text(payment.rooms)),
                DataCell(Text(payment.stayLabel)),
                DataCell(Text(payment.displayMethod)),
                DataCell(Text(payment.processedBy)),
                DataCell(Text(payment.paymentTypeLabel)),
                DataCell(Text(formatPrice(payment.amountReceived))),
                DataCell(Text(_formatDateTime(payment.createdAt))),
              ],
            );
          }).toList(),
        ),
      ),
    );
  }

  Widget _buildPendingTable(List<_SummaryReservation> rows) {
    if (rows.isEmpty) {
      return const Center(child: Text('Aucune réservation impayée.'));
    }

    return SingleChildScrollView(
      padding: const EdgeInsets.all(12),
      child: SingleChildScrollView(
        scrollDirection: Axis.horizontal,
        child: DataTable(
          columnSpacing: 20,
          dataRowMinHeight: 44,
          headingRowHeight: 42,
          columns: const [
            DataColumn(label: Text('Réservation')),
            DataColumn(label: Text('Client')),
            DataColumn(label: Text('Chambres')),
            DataColumn(label: Text('Date séjour')),
            DataColumn(label: Text('Statut')),
            DataColumn(label: Text('Total')),
            DataColumn(label: Text('Payé')),
            DataColumn(label: Text('Reste à payer')),
            DataColumn(label: Text('Dernier mode')),
            DataColumn(label: Text('Pris par')),
          ],
          rows: rows.map((reservation) {
            return DataRow(
              cells: [
                DataCell(Text(reservation.reference)),
                DataCell(Text(reservation.clientName)),
                DataCell(Text(reservation.rooms)),
                DataCell(Text(reservation.stayLabel)),
                DataCell(Text(reservation.paymentStatusLabel)),
                DataCell(Text(formatPrice(reservation.totalAmount))),
                DataCell(Text(formatPrice(reservation.paidAmount))),
                DataCell(Text(formatPrice(reservation.balanceAmount))),
                DataCell(Text(reservation.latestPaymentMethod)),
                DataCell(Text(reservation.latestProcessedBy)),
              ],
            );
          }).toList(),
        ),
      ),
    );
  }
}

class _SummaryChip extends StatelessWidget {
  const _SummaryChip({required this.label, required this.value});

  final String label;
  final String value;

  @override
  Widget build(BuildContext context) {
    return Container(
      padding: const EdgeInsets.symmetric(horizontal: 14, vertical: 10),
      decoration: BoxDecoration(
        color: Colors.white,
        border: Border.all(color: _border),
        borderRadius: BorderRadius.circular(10),
      ),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Text(label, style: const TextStyle(color: _muted, fontSize: 12)),
          const SizedBox(height: 2),
          Text(
            value,
            style: const TextStyle(
              color: _ink,
              fontSize: 14,
              fontWeight: FontWeight.w900,
            ),
          ),
        ],
      ),
    );
  }
}

class _PdfStat {
  const _PdfStat(this.label, this.value);

  final String label;
  final String value;
}

class _SummaryReservation {
  _SummaryReservation({
    required this.reference,
    required this.clientName,
    required this.rooms,
    required this.status,
    required this.paymentStatus,
    required this.checkIn,
    required this.checkOut,
    required this.totalAmount,
    required this.paidAmount,
    required this.balanceAmount,
    required this.payments,
  });

  factory _SummaryReservation.fromJson(Map<String, dynamic> json) {
    final rooms = (json['room_numbers'] ?? json['rooms'] ?? '')
        .toString()
        .trim();
    final payments = (json['payments'] as List<dynamic>? ?? [])
        .whereType<Map>()
        .map(
          (payment) =>
              _SummaryPayment.fromJson(Map<String, dynamic>.from(payment)),
        )
        .toList();

    return _SummaryReservation(
      reference: json['reference']?.toString() ?? 'N/A',
      clientName: json['client_name']?.toString() ?? 'Client',
      rooms: rooms.isNotEmpty ? rooms : 'N/A',
      status: json['status']?.toString() ?? '',
      paymentStatus: json['payment_status']?.toString() ?? 'unbilled',
      checkIn: DateTime.tryParse((json['check_in'] ?? '').toString()),
      checkOut: DateTime.tryParse((json['check_out'] ?? '').toString()),
      totalAmount: int.tryParse(json['total_price']?.toString() ?? '') ?? 0,
      paidAmount:
          int.tryParse(json['paid_amount_ariary']?.toString() ?? '') ?? 0,
      balanceAmount:
          int.tryParse(json['balance_amount_ariary']?.toString() ?? '') ?? 0,
      payments: payments,
    );
  }

  final String reference;
  final String clientName;
  final String rooms;
  final String status;
  final String paymentStatus;
  final DateTime? checkIn;
  final DateTime? checkOut;
  final int totalAmount;
  final int paidAmount;
  final int balanceAmount;
  final List<_SummaryPayment> payments;

  bool get isCancelled => status == 'annule';

  String get paymentStatusLabel {
    return switch (paymentStatus) {
      'paid' => 'Payé',
      'partial' => 'Partiel',
      'unpaid' => 'En attente',
      'unbilled' => 'Non facture',
      _ => paymentStatus.isNotEmpty ? paymentStatus : 'N/A',
    };
  }

  String get latestPaymentMethod {
    if (payments.isEmpty) return 'N/A';
    final latest =
        payments.where((payment) => payment.createdAt != null).toList()..sort(
          (a, b) => (a.createdAt ?? DateTime.fromMillisecondsSinceEpoch(0))
              .compareTo(b.createdAt ?? DateTime.fromMillisecondsSinceEpoch(0)),
        );
    return latest.isNotEmpty ? latest.last.displayMethod : 'N/A';
  }

  String get latestProcessedBy {
    if (payments.isEmpty) return 'N/A';
    final latest =
        payments.where((payment) => payment.createdAt != null).toList()..sort(
          (a, b) => (a.createdAt ?? DateTime.fromMillisecondsSinceEpoch(0))
              .compareTo(b.createdAt ?? DateTime.fromMillisecondsSinceEpoch(0)),
        );
    return latest.isNotEmpty ? latest.last.processedBy : 'N/A';
  }

  String get stayLabel {
    final start =
        checkIn?.toLocal().toIso8601String().substring(0, 10) ?? 'N/A';
    final end = checkOut?.toLocal().toIso8601String().substring(0, 10) ?? 'N/A';
    return '$start au $end';
  }
}

class _SummaryPayment {
  _SummaryPayment({
    required this.reference,
    required this.clientName,
    required this.rooms,
    required this.checkIn,
    required this.checkOut,
    required this.paymentMethod,
    required this.paymentOperator,
    required this.paymentContext,
    required this.processedBy,
    required this.processedRole,
    required this.amountReceived,
    required this.createdAt,
  });

  factory _SummaryPayment.fromJson(Map<String, dynamic> json) {
    return _SummaryPayment(
      reference: json['reference']?.toString() ?? 'N/A',
      clientName: 'Client',
      rooms: 'N/A',
      checkIn: null,
      checkOut: null,
      paymentMethod: json['payment_method']?.toString() ?? 'N/A',
      paymentOperator: json['payment_operator']?.toString() ?? '',
      paymentContext: json['payment_context']?.toString() ?? 'payment',
      processedBy: json['processed_by_name']?.toString() ?? 'N/A',
      processedRole: json['processed_by_role']?.toString() ?? '',
      amountReceived:
          int.tryParse(json['amount_received_ariary']?.toString() ?? '') ?? 0,
      createdAt: DateTime.tryParse(
        (json['created_at'] ?? '').toString(),
      )?.toLocal(),
    );
  }

  final String reference;
  final String clientName;
  final String rooms;
  final DateTime? checkIn;
  final DateTime? checkOut;
  final String paymentMethod;
  final String paymentOperator;
  final String paymentContext;
  final String processedBy;
  final String processedRole;
  final int amountReceived;
  final DateTime? createdAt;

  _SummaryPayment copyWithReservation(_SummaryReservation reservation) {
    return _SummaryPayment(
      reference: reservation.reference,
      clientName: reservation.clientName,
      rooms: reservation.rooms,
      checkIn: reservation.checkIn,
      checkOut: reservation.checkOut,
      paymentMethod: paymentMethod,
      paymentOperator: paymentOperator,
      paymentContext: paymentContext,
      processedBy: processedBy,
      processedRole: processedRole,
      amountReceived: amountReceived,
      createdAt: createdAt,
    );
  }

  String get displayMethod {
    final method = paymentMethod.trim();
    final operator = paymentOperator.trim();
    if (method.toLowerCase() == 'carte bancaire') {
      return 'TPE';
    }
    if (method.toLowerCase() == 'mobile money' && operator.isNotEmpty) {
      return 'Mobile Money / $operator';
    }
    if (method.isNotEmpty) {
      return method;
    }
    return operator.isNotEmpty ? operator : 'N/A';
  }

  String get paymentTypeLabel {
    return paymentContext == 'deposit' ? 'Acompte' : 'Paiement';
  }

  String get stayLabel {
    final start = _shortDate(checkIn);
    final end = _shortDate(checkOut);
    return '$start au $end';
  }
}
