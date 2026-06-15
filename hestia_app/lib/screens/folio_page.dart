import 'dart:convert';
import 'dart:typed_data';

import 'package:flutter/material.dart';
import 'package:http/http.dart' as http;
import 'package:printing/printing.dart';
import 'package:share_plus/share_plus.dart';

import '../core/app_config.dart';
import '../core/formatters.dart';

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

  int get _reservationId => _asInt(widget.reservation['id']);
  int get _invoiceId => _asInt(_folio?['id']);
  bool get _isFinalized => _folio?['status'] == 'finalized';
  bool get _hasPdf => (_folio?['pdf_url'] ?? '').toString().isNotEmpty;
  bool get _canAccess =>
      widget.role == 'admin' ||
      widget.reservation['status']?.toString() == 'arrive';

  @override
  void initState() {
    super.initState();
    _fetchFolio();
  }

  Future<void> _fetchFolio() async {
    if (!_canAccess) {
      if (mounted) {
        setState(() => _isLoading = false);
      }
      return;
    }

    setState(() => _isLoading = true);
    try {
      final response = await http.get(
        Uri.parse('$baseUrl/api/reservations/$_reservationId/folio'),
      );
      if (!mounted) return;
      if (response.statusCode == 200) {
        setState(
          () => _folio = Map<String, dynamic>.from(json.decode(response.body)),
        );
      } else {
        _showMessage('Impossible de charger le folio.', isError: true);
      }
    } catch (e) {
      if (mounted) _showMessage('Erreur réseau : $e', isError: true);
    } finally {
      if (mounted) setState(() => _isLoading = false);
    }
  }

  Future<void> _addExtra() async {
    final result = await _showItemDialog();
    if (result == null) return;

    await _postJson(
      '/api/invoices/$_invoiceId/items',
      result,
      successMessage: 'Extra ajouté.',
    );
  }

  Future<void> _addPayment() async {
    final result = await _showPaymentDialog();
    if (result == null) return;

    await _postJson('/api/invoices/$_invoiceId/payments', {
      ...result,
      'processed_by_name': widget.userName,
    }, successMessage: 'Paiement enregistré.');
  }

  Future<void> _generatePdf() async {
    final discountText = _discountController.text.trim();
    final discountValue = int.tryParse(discountText) ?? 0;
    final payload = <String, dynamic>{'pricing_mode': widget.pricingMode};

    if (discountValue > 0) {
      payload['discount_mode'] = _discountMode;
      payload['discount_value'] = discountValue;
    }

    await _postJson(
      '/api/invoices/$_invoiceId/generate-pdf',
      payload,
      successMessage: 'Facture PDF mise à jour.',
    );
  }

  Future<void> _sendEmail() async {
    if (!_hasPdf) {
      _showMessage('Générez la facture avant l’envoi email.', isError: true);
      return;
    }

    final email = await _showEmailDialog();
    if (email == null) return;

    await _postJson(
      '/api/invoices/$_invoiceId/send-email',
      {'email': email},
      successMessage: 'Facture envoyée par email.',
    );
  }

  Future<void> _postJson(
    String path,
    Map<String, dynamic> body, {
    required String successMessage,
  }) async {
    setState(() => _isBusy = true);
    try {
      final response = await http.post(
        Uri.parse('$baseUrl$path'),
        headers: {'Content-Type': 'application/json'},
        body: json.encode(body),
      );
      if (!mounted) return;

      final decoded = response.body.isNotEmpty
          ? json.decode(response.body)
          : null;
      if (response.statusCode >= 200 && response.statusCode < 300) {
        if (decoded is Map && decoded['invoice'] is Map) {
          setState(
            () => _folio = Map<String, dynamic>.from(decoded['invoice'] as Map),
          );
        } else {
          await _fetchFolio();
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
              final amount = int.tryParse(amountController.text.trim()) ?? 0;
              final quantity =
                  int.tryParse(quantityController.text.trim()) ?? 1;
              if (description.isEmpty || amount <= 0 || quantity <= 0) return;
              Navigator.pop(context, {
                'description': description,
                'type': 'extra',
                'amount_ariary': amount,
                'quantity': quantity,
              });
            },
            child: const Text('Ajouter'),
          ),
        ],
      ),
    );
  }

  Future<Map<String, dynamic>?> _showPaymentDialog() async {
    final amountController = TextEditingController(
      text: _asInt(_folio?['balance_amount_ariary']).toString(),
    );
    final referenceController = TextEditingController();
    String method = 'Espèces';
    const methods = [
      'Espèces',
      'Carte Bancaire',
      'Mobile Money',
      'Chèque',
      'Virement',
      'Acompte',
    ];

    return showDialog<Map<String, dynamic>>(
      context: context,
      builder: (context) => StatefulBuilder(
        builder: (context, setDialogState) => AlertDialog(
          title: const Text('Enregistrer un paiement'),
          content: SingleChildScrollView(
            child: Column(
              mainAxisSize: MainAxisSize.min,
              children: [
                TextField(
                  controller: amountController,
                  keyboardType: TextInputType.number,
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
                  onChanged: (value) =>
                      setDialogState(() => method = value ?? method),
                ),
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
                final amount = int.tryParse(amountController.text.trim()) ?? 0;
                if (amount <= 0) return;
                Navigator.pop(context, {
                  'amount_ariary': amount,
                  'payment_method': method,
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
                  'Le folio n’est accessible qu’après le check-in, sauf pour les administrateurs.',
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
                          _SummaryPanel(folio: _folio!),
                          const SizedBox(height: 16),
                          _DiscountCard(
                            controller: _discountController,
                            mode: _discountMode,
                            onModeChanged: (mode) =>
                                setState(() => _discountMode = mode),
                            onChanged: () => setState(() {}),
                          ),
                          const SizedBox(height: 16),
                          _SectionHeader(
                            title: 'Lignes de facture',
                            action: _isFinalized
                                ? null
                                : TextButton.icon(
                                    onPressed: _addExtra,
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
                                    onPressed: _addPayment,
                                    icon: const Icon(Icons.add_card),
                                    label: const Text('Paiement'),
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
                              ),
                            ),
                          const SizedBox(height: 20),
                          ElevatedButton.icon(
                            onPressed: _isBusy ? null : _generatePdf,
                            icon: const Icon(Icons.picture_as_pdf_outlined),
                            label: Text(
                              _hasPdf
                                  ? 'Mettre à jour le PDF'
                                  : 'Générer la facture PDF',
                            ),
                          ),
                          const SizedBox(height: 10),
                          OutlinedButton.icon(
                            onPressed: _hasPdf ? _openPdfPreview : null,
                            icon: const Icon(Icons.visibility_outlined),
                            label: const Text('Visualiser'),
                          ),
                          OutlinedButton.icon(
                            onPressed: _hasPdf ? _sendEmail : null,
                            icon: const Icon(Icons.mail_outline),
                            label: const Text('Envoyer par email'),
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
  const _SummaryPanel({required this.folio});

  final Map<String, dynamic> folio;

  @override
  Widget build(BuildContext context) {
    final status = folio['status']?.toString() ?? 'open';
    final guest = folio['guest'];
    final loyaltyCount = guest is Map ? _asInt(guest['loyalty_count']) : 0;
    final guestName = guest is Map
        ? (guest['full_name'] ?? guest['first_name'] ?? '').toString().trim()
        : '';
    final hasLoyaltyInfo = guest is Map;

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
          Wrap(
            spacing: 16,
            runSpacing: 12,
            children: [
              _AmountMetric(
                label: 'Total',
                value: _asPrice(folio['total_amount_ariary']),
              ),
              _AmountMetric(
                label: 'Payé',
                value: _asPrice(folio['paid_amount_ariary']),
              ),
              _AmountMetric(
                label: 'Solde',
                value: _asPrice(folio['balance_amount_ariary']),
                emphasized: true,
              ),
            ],
          ),
        ],
      ),
    );
  }

  static String _asPrice(dynamic value) => '${formatPrice(_asInt(value))} Ar';
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
  const _PaymentTile({required this.payment});

  final Map<String, dynamic> payment;

  @override
  Widget build(BuildContext context) {
    return Card(
      child: ListTile(
        leading: const Icon(Icons.payments_outlined, color: _primary),
        title: Text(
          '${formatPrice(_asInt(payment['amount_ariary']))} Ar',
          style: const TextStyle(fontWeight: FontWeight.w900),
        ),
        subtitle: Text(
          [
            payment['payment_method']?.toString(),
            payment['processed_by_name']?.toString(),
            payment['reference']?.toString(),
          ].where((value) => value != null && value.isNotEmpty).join(' - '),
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
