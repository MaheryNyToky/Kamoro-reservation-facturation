import 'dart:convert';
import 'dart:typed_data';
import 'package:flutter/material.dart';
import 'package:http/http.dart' as http;
import 'package:printing/printing.dart';
import 'package:share_plus/share_plus.dart';
import 'package:shared_preferences/shared_preferences.dart';

import '../core/app_config.dart';
import '../core/formatters.dart';
import '../services/pdf_download.dart';

const String _baseUrl = AppConfig.apiBaseUrl;
const Color _ink = Color(0xFF0F172A);
const Color _muted = Color(0xFF64748B);
const Color _border = Color(0xFFE2E8F0);
const Color _sand = Color(0xFFF8FAFC);
const Color _primary = Color(0xFF0F766E);
const Color _primaryDark = Color(0xFF134E4A);
const Color _primarySoft = Color(0xFFE6FFFB);
const List<String> _frMonths = <String>[
  'janvier',
  'février',
  'mars',
  'avril',
  'mai',
  'juin',
  'juillet',
  'août',
  'septembre',
  'octobre',
  'novembre',
  'décembre',
];

class OrganizationDossierPage extends StatefulWidget {
  const OrganizationDossierPage({
    super.key,
    required this.role,
    required this.userName,
  });

  final String role;
  final String userName;

  @override
  State<OrganizationDossierPage> createState() =>
      _OrganizationDossierPageState();
}

class _OrganizationDossierPageState extends State<OrganizationDossierPage> {
  final _searchController = TextEditingController();
  bool _isLoading = true;
  String _errorMessage = '';
  String _viewMode = 'all';
  String _paymentFilter = 'all';
  String? _selectedOrganizationKey;
  String _monthKey = _monthKeyFromDate(DateTime.now());
  String _searchQuery = '';
  final Set<int> _selectedReservationIds = {};
  List<_DossierReservation> _reservations = [];

  @override
  void initState() {
    super.initState();
    _fetchReservations();
  }

  @override
  void dispose() {
    _searchController.dispose();
    super.dispose();
  }

  bool get _canAccess => widget.role == 'admin' || widget.role == 'superadmin';

  List<_DossierReservation> get _organizationReservations {
    final today = DateTime.now();
    final todayOnly = DateTime(today.year, today.month, today.day);
    return _reservations
        .where((reservation) => reservation.isOrganization)
        .where((reservation) => !reservation.isCancelled)
        .where((reservation) => reservation.checkOut != null)
        .where((reservation) {
          final checkOut = reservation.checkOut!;
          return checkOut.isBefore(todayOnly);
        })
        .toList()
      ..sort((a, b) {
        final orgCompare = a.organizationName.compareTo(b.organizationName);
        if (orgCompare != 0) return orgCompare;
        final dateA = a.checkIn ?? DateTime.fromMillisecondsSinceEpoch(0);
        final dateB = b.checkIn ?? DateTime.fromMillisecondsSinceEpoch(0);
        return dateB.compareTo(dateA);
      });
  }

  List<_DossierOrganization> get _organizations {
    final map = <String, List<_DossierReservation>>{};
    for (final reservation in _organizationReservations) {
      map.putIfAbsent(reservation.organizationKey, () => []).add(reservation);
    }

    final orgs =
        map.entries
            .map(
              (entry) => _DossierOrganization(
                key: entry.key,
                name: entry.value.first.organizationName,
                reservations: entry.value,
                organization: entry.value.first.organization,
              ),
            )
            .toList()
          ..sort((a, b) {
            final totalCompare = b.totalAmount.compareTo(a.totalAmount);
            if (totalCompare != 0) return totalCompare;
            return a.name.compareTo(b.name);
          });

    return orgs;
  }

  List<_DossierOrganization> get _filteredOrganizations {
    if (_searchQuery.isEmpty) {
      return _organizations;
    }
    return _organizations
        .where(
          (organization) =>
              organization.name.toLowerCase().contains(_searchQuery),
        )
        .toList();
  }

  _DossierOrganization? get _selectedOrganization {
    final orgs = _organizations;
    if (orgs.isEmpty) return null;
    final current = _selectedOrganizationKey;
    if (current != null) {
      for (final org in orgs) {
        if (org.key == current) return org;
      }
    }
    return orgs.first;
  }

  List<_DossierReservation> get _visibleReservations {
    final organization = _selectedOrganization;
    if (organization == null) return const [];
    return _filteredReservationsForScope(organization.reservations);
  }

  List<_DossierReservation> _filteredReservationsForScope(
    List<_DossierReservation> reservations,
  ) {
    final scoped = _viewMode == 'month'
        ? reservations
              .where((reservation) => reservation.monthKey == _monthKey)
              .toList()
        : reservations.toList();

    return scoped.where(_matchesPaymentFilter).toList();
  }

  bool _matchesPaymentFilter(_DossierReservation reservation) {
    return switch (_paymentFilter) {
      'paid' => reservation.paymentStatus == 'paid',
      'unpaid' =>
        reservation.paymentStatus == 'unpaid' ||
            reservation.paymentStatus == 'partial' ||
            reservation.paymentStatus == 'unbilled',
      _ => true,
    };
  }

  List<_DossierReservation> get _selectedReservations {
    final visible = _viewMode == 'all'
        ? _selectedOrganization?.reservations ?? const <_DossierReservation>[]
        : _visibleReservations;
    if (_selectedReservationIds.isEmpty) {
      return const [];
    }
    return visible
        .where(
          (reservation) => _selectedReservationIds.contains(reservation.id),
        )
        .toList()
      ..sort((a, b) {
        final aCheckIn = a.checkIn ?? DateTime.fromMillisecondsSinceEpoch(0);
        final bCheckIn = b.checkIn ?? DateTime.fromMillisecondsSinceEpoch(0);
        final dateCompare = aCheckIn.compareTo(bCheckIn);
        if (dateCompare != 0) return dateCompare;
        return a.id.compareTo(b.id);
      });
  }

  List<String> get _availableMonthKeys {
    final organization = _selectedOrganization;
    if (organization == null) return const [];
    final keys =
        organization.reservations
            .map((reservation) => reservation.monthKey)
            .where((key) => key.isNotEmpty)
            .toSet()
            .toList()
          ..sort((a, b) => b.compareTo(a));
    return keys;
  }

  int get _totalAmount =>
      _selectedReservations.fold<int>(0, (sum, item) => sum + item.totalAmount);

  int get _paidAmount =>
      _selectedReservations.fold<int>(0, (sum, item) => sum + item.paidAmount);

  int get _balanceAmount => _selectedReservations.fold<int>(
    0,
    (sum, item) => sum + item.balanceAmount,
  );

  int get _reservationCount => _selectedReservations.length;

  void _syncSelectionToVisibleReservations({bool clearSelection = false}) {
    final visibleIds = _visibleReservations
        .map((reservation) => reservation.id)
        .toSet();
    setState(() {
      if (clearSelection) {
        _selectedReservationIds.clear();
        return;
      }
      _selectedReservationIds.removeWhere((id) => !visibleIds.contains(id));
    });
  }

  bool _isReservationSelected(_DossierReservation reservation) {
    return _selectedReservationIds.contains(reservation.id);
  }

  void _toggleReservationSelection(_DossierReservation reservation) {
    setState(() {
      if (_selectedReservationIds.contains(reservation.id)) {
        _selectedReservationIds.remove(reservation.id);
      } else {
        _selectedReservationIds.add(reservation.id);
      }
    });
  }

  void _selectAllVisibleReservations() {
    setState(() {
      _selectedReservationIds
        ..clear()
        ..addAll(_visibleReservations.map((reservation) => reservation.id));
    });
  }

  void _selectAllOrganizationReservations() {
    final organization = _selectedOrganization;
    if (organization == null) return;
    setState(() {
      _selectedReservationIds
        ..clear()
        ..addAll(
          organization.reservations.map((reservation) => reservation.id),
        );
    });
  }

  void _clearSelection() {
    setState(() => _selectedReservationIds.clear());
  }

  Future<void> _fetchReservations() async {
    if (mounted) {
      setState(() {
        _isLoading = true;
        _errorMessage = '';
      });
    }

    const cacheKey = 'organization_dossier:reservations_all';
    try {
      final response = await http
          .get(Uri.parse('$_baseUrl/api/reservations/all?date=all&status=all'))
          .timeout(const Duration(seconds: 8));
      if (response.statusCode != 200) {
        throw Exception('Erreur ${response.statusCode}');
      }

      final decoded = json.decode(response.body);
      final data = decoded is List ? decoded : const [];
      final reservations = data
          .whereType<Map>()
          .map(
            (item) =>
                _DossierReservation.fromJson(Map<String, dynamic>.from(item)),
          )
          .toList();

      if (!mounted) return;
      setState(() {
        _reservations = reservations;
        _errorMessage = '';
      });

      final orgs = _organizations;
      if (orgs.isNotEmpty) {
        _selectedOrganizationKey ??= orgs.first.key;
        _monthKey = _availableMonthKeys.isNotEmpty
            ? _availableMonthKeys.first
            : _monthKey;
      }

      final prefs = await SharedPreferences.getInstance();
      await prefs.setString(cacheKey, json.encode(data));
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
                (item) => _DossierReservation.fromJson(
                  Map<String, dynamic>.from(item),
                ),
              )
              .toList();
          _errorMessage = 'Mode hors ligne : dossier local affiché.';
        });
      } else if (mounted) {
        setState(() {
          _errorMessage = 'Impossible de charger le dossier organisme.';
        });
      }
    } finally {
      if (mounted) {
        setState(() => _isLoading = false);
      }
    }
  }

  void _selectOrganization(_DossierOrganization organization) {
    setState(() {
      _selectedOrganizationKey = organization.key;
      final availableMonths =
          organization.reservations
              .map((reservation) => reservation.monthKey)
              .where((value) => value.isNotEmpty)
              .toSet()
              .toList()
            ..sort((a, b) => b.compareTo(a));
      if (availableMonths.isNotEmpty && !availableMonths.contains(_monthKey)) {
        _monthKey = availableMonths.first;
      }
      _selectedReservationIds.clear();
    });
  }

  Future<void> _pickMonth() async {
    final initialDate = _monthFromKey(_monthKey) ?? DateTime.now();
    final picked = await showDialog<DateTime>(
      context: context,
      builder: (context) => _MonthPickerDialog(initialDate: initialDate),
    );
    if (picked == null) return;
    setState(() {
      _monthKey = _monthKeyFromDate(picked);
      _viewMode = 'month';
    });
    _syncSelectionToVisibleReservations();
  }

  Future<Uint8List> _buildPdfBytes() async {
    final organization = _selectedOrganization;
    if (organization == null) return Uint8List(0);
    final reservations = _selectedReservations;
    if (reservations.isEmpty) return Uint8List(0);

    final response = await http
        .post(
          Uri.parse(
            '$_baseUrl/api/organization-dossiers/${organization.organizationId}/invoice-pdf',
          ),
          headers: const {
            'Accept': 'application/pdf',
            'Content-Type': 'application/json',
          },
          body: json.encode({
            'document_type': 'facture',
            'reservation_ids': reservations
                .map((reservation) => reservation.id)
                .toList(),
          }),
        )
        .timeout(const Duration(seconds: 15));

    if (response.statusCode != 200 || response.bodyBytes.isEmpty) {
      final decoded = response.body.isNotEmpty
          ? json.decode(response.body)
          : null;
      final message = decoded is Map && decoded['message'] != null
          ? decoded['message'].toString()
          : 'Impossible de générer la facture.';
      throw Exception(message);
    }

    return response.bodyBytes;
  }

  Future<void> _openPreview() async {
    final bytes = await _buildPdfBytes();
    if (!mounted || bytes.isEmpty) return;
    await Navigator.push(
      context,
      MaterialPageRoute(
        builder: (_) => Scaffold(
          appBar: AppBar(title: const Text('Aperçu facture')),
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
    if (!mounted || bytes.isEmpty) return;
    final message = await savePdfToDownloads(bytes, _pdfFileName());
    if (!mounted) return;
    ScaffoldMessenger.of(
      context,
    ).showSnackBar(SnackBar(content: Text(message)));
  }

  Future<void> _sharePdf() async {
    final bytes = await _buildPdfBytes();
    if (!mounted || bytes.isEmpty) return;
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
    if (bytes.isEmpty) return;
    await Printing.layoutPdf(onLayout: (_) async => bytes);
  }

  String _pdfFileName() {
    final organization = _selectedOrganization?.name ?? 'organisme';
    final safeOrg = organization
        .toLowerCase()
        .replaceAll(RegExp(r'[^a-z0-9]+'), '_')
        .replaceAll(RegExp(r'^_+|_+$'), '');
    final suffix = _selectedReservations.isEmpty
        ? (_viewMode == 'all' ? 'historique' : _monthKey)
        : 'facture_groupee';
    return 'facture_${safeOrg.isEmpty ? 'organisme' : safeOrg}_$suffix.pdf';
  }

  String get _selectionScopeLabel {
    if (_selectedReservations.isEmpty) {
      return _viewMode == 'all'
          ? 'Aucun séjour sélectionné'
          : 'Sélectionne des séjours pour générer la facture';
    }

    final months =
        _selectedReservations
            .map((reservation) => reservation.monthKey)
            .where((value) => value.isNotEmpty)
            .toSet()
            .toList()
          ..sort((a, b) => a.compareTo(b));

    if (months.length == 1) {
      return 'Facture des séjours de ${_monthKeyLabel(months.first)}';
    }
    return 'Facture groupée sur ${months.length} mois';
  }

  String get _paymentFilterLabel {
    return switch (_paymentFilter) {
      'paid' => 'Payés',
      'unpaid' => 'Non payés',
      _ => 'Tout',
    };
  }

  Widget _buildSummaryChip(String label, String value) {
    return Container(
      padding: const EdgeInsets.symmetric(horizontal: 14, vertical: 12),
      decoration: BoxDecoration(
        color: Colors.white,
        borderRadius: BorderRadius.circular(18),
        border: Border.all(color: _border),
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

  @override
  Widget build(BuildContext context) {
    if (!_canAccess) {
      return const Scaffold(
        body: Center(child: Text('Accès réservé aux administrateurs.')),
      );
    }

    final organization = _selectedOrganization;
    final orgs = _organizations;
    final visible = _visibleReservations;
    final wide = MediaQuery.of(context).size.width >= 1100;

    return Scaffold(
      appBar: AppBar(
        title: const Text('Dossier organisme'),
        actions: [
          IconButton(
            tooltip: 'Rafraîchir',
            onPressed: _isLoading ? null : _fetchReservations,
            icon: const Icon(Icons.refresh),
          ),
        ],
      ),
      body: Container(
        decoration: const BoxDecoration(
          gradient: LinearGradient(
            begin: Alignment.topLeft,
            end: Alignment.bottomRight,
            colors: [_sand, Color(0xFFEFF6F5)],
          ),
        ),
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
                      Expanded(
                        child: wide
                            ? Row(
                                crossAxisAlignment: CrossAxisAlignment.stretch,
                                children: [
                                  SizedBox(
                                    width: 340,
                                    child: _buildOrganizationPanel(orgs),
                                  ),
                                  const SizedBox(width: 16),
                                  Expanded(
                                    child: _buildDetailPanel(
                                      organization: organization,
                                      visibleReservations: visible,
                                    ),
                                  ),
                                ],
                              )
                            : Column(
                                crossAxisAlignment: CrossAxisAlignment.stretch,
                                children: [
                                  _buildOrganizationPanel(orgs),
                                  const SizedBox(height: 16),
                                  Expanded(
                                    child: _buildDetailPanel(
                                      organization: organization,
                                      visibleReservations: visible,
                                    ),
                                  ),
                                ],
                              ),
                      ),
                    ],
                  ),
                ),
        ),
      ),
    );
  }

  Widget _buildOrganizationPanel(List<_DossierOrganization> organizations) {
    final filteredOrganizations = _filteredOrganizations;
    return Container(
      decoration: BoxDecoration(
        color: Colors.white.withValues(alpha: 0.9),
        borderRadius: BorderRadius.circular(24),
        border: Border.all(color: _border),
      ),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          Padding(
            padding: const EdgeInsets.fromLTRB(16, 16, 16, 12),
            child: TextField(
              controller: _searchController,
              decoration: InputDecoration(
                hintText: 'Rechercher un organisme',
                prefixIcon: const Icon(Icons.search),
                filled: true,
                fillColor: _sand,
                border: OutlineInputBorder(
                  borderRadius: BorderRadius.circular(16),
                  borderSide: BorderSide.none,
                ),
                isDense: true,
              ),
              onChanged: (value) {
                setState(() => _searchQuery = value.trim().toLowerCase());
              },
            ),
          ),
          Padding(
            padding: const EdgeInsets.symmetric(horizontal: 16),
            child: Text(
              'Organismes',
              style: Theme.of(
                context,
              ).textTheme.titleMedium?.copyWith(fontWeight: FontWeight.w900),
            ),
          ),
          const SizedBox(height: 8),
          Expanded(
            child: organizations.isEmpty
                ? const Center(
                    child: Padding(
                      padding: EdgeInsets.all(24),
                      child: Text('Aucun dossier organisme trouvé.'),
                    ),
                  )
                : ListView.separated(
                    padding: const EdgeInsets.fromLTRB(12, 0, 12, 12),
                    itemBuilder: (context, index) {
                      final org = filteredOrganizations[index];
                      final selected = org.key == _selectedOrganization?.key;
                      return InkWell(
                        onTap: () => _selectOrganization(org),
                        borderRadius: BorderRadius.circular(18),
                        child: AnimatedContainer(
                          duration: const Duration(milliseconds: 180),
                          padding: const EdgeInsets.all(14),
                          decoration: BoxDecoration(
                            color: selected ? _primarySoft : _sand,
                            borderRadius: BorderRadius.circular(18),
                            border: Border.all(
                              color: selected ? _primary : _border,
                              width: selected ? 1.3 : 1,
                            ),
                          ),
                          child: Column(
                            crossAxisAlignment: CrossAxisAlignment.start,
                            children: [
                              Text(
                                org.name,
                                style: TextStyle(
                                  fontWeight: FontWeight.w900,
                                  color: selected ? _primaryDark : _ink,
                                ),
                              ),
                              const SizedBox(height: 8),
                              Wrap(
                                spacing: 6,
                                runSpacing: 6,
                                children: [
                                  _smallChip(
                                    '${org.reservations.length} séjours',
                                  ),
                                  _smallChip(formatPrice(org.totalAmount)),
                                ],
                              ),
                              const SizedBox(height: 8),
                              Text(
                                _monthSummary(org),
                                style: const TextStyle(
                                  color: _muted,
                                  fontSize: 12,
                                ),
                              ),
                            ],
                          ),
                        ),
                      );
                    },
                    separatorBuilder: (context, index) =>
                        const SizedBox(height: 10),
                    itemCount: filteredOrganizations.length,
                  ),
          ),
        ],
      ),
    );
  }

  Widget _buildDetailPanel({
    required _DossierOrganization? organization,
    required List<_DossierReservation> visibleReservations,
  }) {
    if (organization == null) {
      return Container(
        decoration: BoxDecoration(
          color: Colors.white.withValues(alpha: 0.88),
          borderRadius: BorderRadius.circular(24),
          border: Border.all(color: _border),
        ),
        child: const Center(
          child: Text('Sélectionne un organisme pour commencer.'),
        ),
      );
    }

    return Container(
      decoration: BoxDecoration(
        color: Colors.white.withValues(alpha: 0.9),
        borderRadius: BorderRadius.circular(24),
        border: Border.all(color: _border),
      ),
      child: Padding(
        padding: const EdgeInsets.all(18),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.stretch,
          children: [
            Row(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Expanded(
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                      Text(
                        organization.name,
                        style: Theme.of(context).textTheme.headlineSmall
                            ?.copyWith(
                              fontWeight: FontWeight.w900,
                              color: _ink,
                            ),
                      ),
                      const SizedBox(height: 6),
                      Text(
                        'Séjours à facturer',
                        style: Theme.of(context).textTheme.bodyMedium?.copyWith(
                          color: _muted,
                          fontWeight: FontWeight.w600,
                        ),
                      ),
                    ],
                  ),
                ),
                const SizedBox(width: 12),
                SegmentedButton<String>(
                  segments: const [
                    ButtonSegment(
                      value: 'month',
                      label: Text('Mois'),
                      icon: Icon(Icons.calendar_month_outlined),
                    ),
                    ButtonSegment(
                      value: 'all',
                      label: Text('Tout'),
                      icon: Icon(Icons.all_inclusive),
                    ),
                  ],
                  selected: {_viewMode},
                  onSelectionChanged: (selection) {
                    setState(() {
                      _viewMode = selection.first;
                    });
                  },
                ),
              ],
            ),
            const SizedBox(height: 12),
            Wrap(
              spacing: 10,
              runSpacing: 10,
              children: [
                OutlinedButton.icon(
                  onPressed: _visibleReservations.isEmpty
                      ? null
                      : _selectAllVisibleReservations,
                  icon: const Icon(Icons.calendar_view_month_outlined),
                  label: const Text('Sélectionner la vue'),
                ),
                OutlinedButton.icon(
                  onPressed: organization.reservations.isEmpty
                      ? null
                      : _selectAllOrganizationReservations,
                  icon: const Icon(Icons.all_inclusive),
                  label: const Text('Tout sélectionner'),
                ),
                OutlinedButton.icon(
                  onPressed: _selectedReservationIds.isEmpty
                      ? null
                      : _clearSelection,
                  icon: const Icon(Icons.clear_outlined),
                  label: const Text('Vider la sélection'),
                ),
              ],
            ),
            const SizedBox(height: 12),
            Wrap(
              spacing: 10,
              runSpacing: 10,
              crossAxisAlignment: WrapCrossAlignment.center,
              children: [
                const Text(
                  'Paiement',
                  style: TextStyle(color: _muted, fontWeight: FontWeight.w800),
                ),
                SegmentedButton<String>(
                  segments: const [
                    ButtonSegment(
                      value: 'all',
                      label: Text('Tout'),
                      icon: Icon(Icons.grid_view_outlined),
                    ),
                    ButtonSegment(
                      value: 'unpaid',
                      label: Text('Non payés'),
                      icon: Icon(Icons.hourglass_bottom_outlined),
                    ),
                    ButtonSegment(
                      value: 'paid',
                      label: Text('Payés'),
                      icon: Icon(Icons.verified_outlined),
                    ),
                  ],
                  selected: {_paymentFilter},
                  onSelectionChanged: (selection) {
                    setState(() {
                      _paymentFilter = selection.first;
                    });
                    _syncSelectionToVisibleReservations(clearSelection: true);
                  },
                ),
              ],
            ),
            const SizedBox(height: 14),
            Wrap(
              spacing: 10,
              runSpacing: 10,
              crossAxisAlignment: WrapCrossAlignment.center,
              children: [
                if (_viewMode == 'month')
                  OutlinedButton.icon(
                    onPressed: _pickMonth,
                    icon: const Icon(Icons.date_range_outlined),
                    label: Text('Mois ${_monthKeyLabel(_monthKey)}'),
                  ),
                _buildSummaryChip('Séjours', _reservationCount.toString()),
                _buildSummaryChip('Total', formatPrice(_totalAmount)),
                _buildSummaryChip('Payé', formatPrice(_paidAmount)),
                _buildSummaryChip('Reste', formatPrice(_balanceAmount)),
                _buildSummaryChip('Filtre', _paymentFilterLabel),
                _buildSummaryChip('Sélection', _selectionScopeLabel),
              ],
            ),
            const SizedBox(height: 14),
            Wrap(
              spacing: 10,
              runSpacing: 10,
              children: [
                OutlinedButton.icon(
                  onPressed: _selectedReservations.isEmpty
                      ? null
                      : _openPreview,
                  icon: const Icon(Icons.visibility_outlined),
                  label: const Text('Aperçu facture'),
                ),
                OutlinedButton.icon(
                  onPressed: _selectedReservations.isEmpty
                      ? null
                      : _downloadPdf,
                  icon: const Icon(Icons.download_outlined),
                  label: const Text('Télécharger facture'),
                ),
                OutlinedButton.icon(
                  onPressed: _selectedReservations.isEmpty ? null : _sharePdf,
                  icon: const Icon(Icons.ios_share),
                  label: const Text('Partager facture'),
                ),
                OutlinedButton.icon(
                  onPressed: _selectedReservations.isEmpty ? null : _printPdf,
                  icon: const Icon(Icons.print_outlined),
                  label: const Text('Imprimer facture'),
                ),
              ],
            ),
            const SizedBox(height: 14),
            Expanded(
              child: Container(
                decoration: BoxDecoration(
                  color: _sand,
                  borderRadius: BorderRadius.circular(18),
                  border: Border.all(color: _border),
                ),
                child: _viewMode == 'all'
                    ? _buildMonthGroupedList(organization)
                    : visibleReservations.isEmpty
                    ? const Center(child: Text('Aucun séjour pour ce mois.'))
                    : SingleChildScrollView(
                        padding: const EdgeInsets.all(12),
                        child: SingleChildScrollView(
                          scrollDirection: Axis.horizontal,
                          child: DataTable(
                            columnSpacing: 18,
                            dataRowMinHeight: 44,
                            headingRowHeight: 42,
                            columns: const [
                              DataColumn(label: Text('Sélect.')),
                              DataColumn(label: Text('Réf')),
                              DataColumn(label: Text('Séjour')),
                              DataColumn(label: Text('Prestations')),
                              DataColumn(label: Text('Total')),
                              DataColumn(label: Text('Payé')),
                              DataColumn(label: Text('Reste')),
                              DataColumn(label: Text('Statut')),
                            ],
                            rows: visibleReservations.map((reservation) {
                              return DataRow(
                                cells: [
                                  DataCell(
                                    Checkbox(
                                      value: _isReservationSelected(
                                        reservation,
                                      ),
                                      onChanged: (_) =>
                                          _toggleReservationSelection(
                                            reservation,
                                          ),
                                    ),
                                  ),
                                  DataCell(Text(reservation.reference)),
                                  DataCell(Text(reservation.stayLabel)),
                                  DataCell(Text(reservation.prestations)),
                                  DataCell(
                                    Text(formatPrice(reservation.totalAmount)),
                                  ),
                                  DataCell(
                                    Text(formatPrice(reservation.paidAmount)),
                                  ),
                                  DataCell(
                                    Text(
                                      formatPrice(reservation.balanceAmount),
                                    ),
                                  ),
                                  DataCell(
                                    Text(reservation.paymentStatusLabel),
                                  ),
                                ],
                              );
                            }).toList(),
                          ),
                        ),
                      ),
              ),
            ),
          ],
        ),
      ),
    );
  }

  Widget _buildMonthGroupedList(_DossierOrganization organization) {
    final grouped = _groupReservationsByMonth(
      _filteredReservationsForScope(organization.reservations),
    );
    if (grouped.isEmpty) {
      return const Center(child: Text('Aucun séjour pour cet organisme.'));
    }

    return ListView.separated(
      padding: const EdgeInsets.all(12),
      itemBuilder: (context, index) {
        final entry = grouped.entries.elementAt(index);
        final reservations = entry.value;
        return Card(
          elevation: 0,
          color: Colors.white,
          shape: RoundedRectangleBorder(
            borderRadius: BorderRadius.circular(18),
            side: BorderSide(color: _border),
          ),
          child: ExpansionTile(
            initiallyExpanded: index == 0,
            title: Row(
              children: [
                Checkbox(
                  value: reservations.every(_isReservationSelected)
                      ? true
                      : (reservations.any(_isReservationSelected)
                            ? null
                            : false),
                  tristate: true,
                  onChanged: (_) {
                    setState(() {
                      final allSelected = reservations.every(
                        _isReservationSelected,
                      );
                      for (final reservation in reservations) {
                        if (allSelected) {
                          _selectedReservationIds.remove(reservation.id);
                        } else {
                          _selectedReservationIds.add(reservation.id);
                        }
                      }
                    });
                  },
                ),
                Expanded(
                  child: Text(
                    _monthKeyLabel(entry.key),
                    style: const TextStyle(
                      fontWeight: FontWeight.w900,
                      color: _ink,
                    ),
                  ),
                ),
                Text(
                  '${reservations.length} séjour${reservations.length > 1 ? 's' : ''}',
                  style: const TextStyle(color: _muted),
                ),
              ],
            ),
            subtitle: Text(
              '${formatPrice(reservations.fold<int>(0, (sum, reservation) => sum + reservation.totalAmount))} total',
            ),
            trailing: TextButton.icon(
              onPressed: () {
                setState(() {
                  _viewMode = 'month';
                  _monthKey = reservations.first.monthKey;
                  _selectedReservationIds
                    ..removeWhere(
                      (id) => !_visibleReservations.any(
                        (reservation) => reservation.id == id,
                      ),
                    )
                    ..addAll(reservations.map((reservation) => reservation.id));
                });
              },
              icon: const Icon(Icons.checklist_outlined),
              label: const Text('Sélectionner le mois'),
            ),
            childrenPadding: const EdgeInsets.fromLTRB(12, 0, 12, 12),
            children: [
              SingleChildScrollView(
                scrollDirection: Axis.horizontal,
                child: DataTable(
                  columnSpacing: 18,
                  dataRowMinHeight: 44,
                  headingRowHeight: 42,
                  columns: const [
                    DataColumn(label: Text('Sélect.')),
                    DataColumn(label: Text('Réf')),
                    DataColumn(label: Text('Séjour')),
                    DataColumn(label: Text('Prestations')),
                    DataColumn(label: Text('Total')),
                    DataColumn(label: Text('Payé')),
                    DataColumn(label: Text('Reste')),
                    DataColumn(label: Text('Statut')),
                  ],
                  rows: reservations.map((reservation) {
                    return DataRow(
                      cells: [
                        DataCell(
                          Checkbox(
                            value: _isReservationSelected(reservation),
                            onChanged: (_) =>
                                _toggleReservationSelection(reservation),
                          ),
                        ),
                        DataCell(Text(reservation.reference)),
                        DataCell(Text(reservation.stayLabel)),
                        DataCell(Text(reservation.prestations)),
                        DataCell(Text(formatPrice(reservation.totalAmount))),
                        DataCell(Text(formatPrice(reservation.paidAmount))),
                        DataCell(Text(formatPrice(reservation.balanceAmount))),
                        DataCell(Text(reservation.paymentStatusLabel)),
                      ],
                    );
                  }).toList(),
                ),
              ),
            ],
          ),
        );
      },
      separatorBuilder: (context, index) => const SizedBox(height: 10),
      itemCount: grouped.length,
    );
  }

  Widget _smallChip(String label) {
    return Container(
      padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 6),
      decoration: BoxDecoration(
        color: Colors.white,
        borderRadius: BorderRadius.circular(999),
        border: Border.all(color: _border),
      ),
      child: Text(
        label,
        style: const TextStyle(
          color: _ink,
          fontSize: 11,
          fontWeight: FontWeight.w700,
        ),
      ),
    );
  }

  String _monthSummary(_DossierOrganization organization) {
    final months =
        organization.reservations
            .map((reservation) => reservation.monthKey)
            .where((value) => value.isNotEmpty)
            .toSet()
            .toList()
          ..sort((a, b) => b.compareTo(a));
    if (months.isEmpty) return 'Aucun mois disponible';
    if (months.length == 1) return _monthKeyLabel(months.first);
    return '${_monthKeyLabel(months.first)} et ${months.length - 1} autre(s)';
  }

  Map<String, List<_DossierReservation>> _groupReservationsByMonth(
    List<_DossierReservation> reservations,
  ) {
    final groups = <String, List<_DossierReservation>>{};
    for (final reservation in reservations) {
      final key = reservation.monthKey;
      groups.putIfAbsent(key, () => []).add(reservation);
    }
    final entries = groups.entries.toList()
      ..sort((a, b) => b.key.compareTo(a.key));
    return Map.fromEntries(entries);
  }
}

String _monthKeyFromDate(DateTime date) {
  final year = date.year.toString().padLeft(4, '0');
  final month = date.month.toString().padLeft(2, '0');
  return '$year-$month';
}

String _monthKeyLabel(String key) {
  final parts = key.split('-');
  if (parts.length != 2) return key;
  final year = int.tryParse(parts[0]) ?? DateTime.now().year;
  final month = int.tryParse(parts[1]) ?? DateTime.now().month;
  if (month < 1 || month > 12) return key;
  return '${_frMonths[month - 1]} $year';
}

String _formatStayDate(DateTime date) {
  final day = date.day.toString().padLeft(2, '0');
  final month = date.month.toString().padLeft(2, '0');
  final year = date.year.toString().padLeft(4, '0');
  return '$day-$month-$year';
}

DateTime? _monthFromKey(String key) {
  final parts = key.split('-');
  if (parts.length != 2) return null;
  final year = int.tryParse(parts[0]);
  final month = int.tryParse(parts[1]);
  if (year == null || month == null) return null;
  return DateTime(year, month, 1);
}

class _DossierOrganization {
  const _DossierOrganization({
    required this.key,
    required this.name,
    required this.reservations,
    required this.organization,
  });

  final String key;
  final String name;
  final List<_DossierReservation> reservations;
  final Map<String, dynamic>? organization;

  int get organizationId => _asInt(organization?['id']);

  int get totalAmount => reservations.fold<int>(
    0,
    (sum, reservation) => sum + reservation.totalAmount,
  );
}

class _DossierReservation {
  _DossierReservation({
    required this.id,
    required this.reference,
    required this.clientName,
    required this.rooms,
    required this.prestations,
    required this.status,
    required this.paymentStatus,
    required this.checkIn,
    required this.checkOut,
    required this.totalAmount,
    required this.paidAmount,
    required this.balanceAmount,
    required this.organization,
    required this.bookingType,
  });

  factory _DossierReservation.fromJson(Map<String, dynamic> json) {
    final rooms = (json['room_numbers'] ?? json['rooms'] ?? '')
        .toString()
        .trim();
    final prestations = (json['prestations'] ?? rooms).toString().trim();
    final organization = json['organization'] is Map<String, dynamic>
        ? Map<String, dynamic>.from(json['organization'] as Map)
        : null;
    return _DossierReservation(
      id: _asInt(json['id']),
      reference: json['reference']?.toString() ?? 'N/A',
      clientName: json['client_name']?.toString() ?? 'Client',
      rooms: rooms.isNotEmpty ? rooms : 'N/A',
      prestations: prestations.isNotEmpty
          ? prestations
          : (rooms.isNotEmpty ? rooms : 'N/A'),
      status: json['status']?.toString() ?? '',
      paymentStatus: json['payment_status']?.toString() ?? 'unbilled',
      checkIn: DateTime.tryParse((json['check_in'] ?? '').toString()),
      checkOut: DateTime.tryParse((json['check_out'] ?? '').toString()),
      totalAmount: () {
        final invoiceTotal = _asInt(json['invoice_total_amount_ariary']);
        final fallbackTotal = _asInt(json['total_price']);
        return invoiceTotal > 0 ? invoiceTotal : fallbackTotal;
      }(),
      paidAmount: () {
        final invoicePaid = _asInt(json['invoice_paid_amount_ariary']);
        final fallbackPaid = _asInt(json['paid_amount_ariary']);
        return invoicePaid > 0 ? invoicePaid : fallbackPaid;
      }(),
      balanceAmount: () {
        final invoiceBalance = _asInt(json['invoice_balance_amount_ariary']);
        final fallbackBalance = _asInt(json['balance_amount_ariary']);
        return invoiceBalance > 0 ? invoiceBalance : fallbackBalance;
      }(),
      organization: organization,
      bookingType: json['booking_type']?.toString() ?? 'individual',
    );
  }

  final int id;
  final String reference;
  final String clientName;
  final String rooms;
  final String prestations;
  final String status;
  final String paymentStatus;
  final DateTime? checkIn;
  final DateTime? checkOut;
  final int totalAmount;
  final int paidAmount;
  final int balanceAmount;
  final Map<String, dynamic>? organization;
  final String bookingType;

  bool get isCancelled => status == 'annule';

  bool get isOrganization =>
      bookingType == 'organization' || organization != null;

  String get organizationKey {
    final organizationId = organization?['id']?.toString().trim() ?? '';
    if (organizationId.isNotEmpty) return 'id:$organizationId';
    final normalizedName = organizationName.toLowerCase();
    return 'name:$normalizedName';
  }

  String get organizationName {
    final name = organization?['name']?.toString().trim() ?? '';
    return name.isNotEmpty ? name : clientName;
  }

  String get monthKey {
    if (checkIn == null) return '';
    return _monthKeyFromDate(checkIn!);
  }

  String get stayLabel {
    final start = checkIn == null ? 'N/A' : _formatStayDate(checkIn!);
    final end = checkOut == null ? 'N/A' : _formatStayDate(checkOut!);
    return '$start au $end';
  }

  String get paymentStatusLabel {
    return switch (paymentStatus) {
      'paid' => 'Payé',
      'partial' => 'Partiel',
      'unpaid' => 'En attente',
      'unbilled' => 'Non facturé',
      _ => paymentStatus.isNotEmpty ? paymentStatus : 'N/A',
    };
  }
}

int _asInt(dynamic value) {
  if (value is int) return value;
  if (value is num) return value.toInt();
  return int.tryParse(value?.toString() ?? '') ?? 0;
}

class _MonthPickerDialog extends StatefulWidget {
  const _MonthPickerDialog({required this.initialDate});

  final DateTime initialDate;

  @override
  State<_MonthPickerDialog> createState() => _MonthPickerDialogState();
}

class _MonthPickerDialogState extends State<_MonthPickerDialog> {
  late int _year;

  @override
  void initState() {
    super.initState();
    _year = widget.initialDate.year;
  }

  @override
  Widget build(BuildContext context) {
    return AlertDialog(
      title: const Text('Sélectionner un mois'),
      content: SizedBox(
        width: 420,
        child: Column(
          mainAxisSize: MainAxisSize.min,
          children: [
            Row(
              mainAxisAlignment: MainAxisAlignment.spaceBetween,
              children: [
                IconButton(
                  onPressed: () => setState(() => _year -= 1),
                  icon: const Icon(Icons.chevron_left),
                ),
                Text(
                  '$_year',
                  style: Theme.of(
                    context,
                  ).textTheme.titleLarge?.copyWith(fontWeight: FontWeight.w900),
                ),
                IconButton(
                  onPressed: () => setState(() => _year += 1),
                  icon: const Icon(Icons.chevron_right),
                ),
              ],
            ),
            const SizedBox(height: 8),
            GridView.builder(
              shrinkWrap: true,
              physics: const NeverScrollableScrollPhysics(),
              itemCount: 12,
              gridDelegate: const SliverGridDelegateWithFixedCrossAxisCount(
                crossAxisCount: 3,
                mainAxisSpacing: 8,
                crossAxisSpacing: 8,
                childAspectRatio: 2.5,
              ),
              itemBuilder: (context, index) {
                final month = index + 1;
                return FilledButton.tonal(
                  onPressed: () {
                    Navigator.pop(context, DateTime(_year, month, 1));
                  },
                  child: Text(_frMonths[index].substring(0, 3).toUpperCase()),
                );
              },
            ),
          ],
        ),
      ),
      actions: [
        TextButton(
          onPressed: () => Navigator.pop(context),
          child: const Text('Annuler'),
        ),
      ],
    );
  }
}
