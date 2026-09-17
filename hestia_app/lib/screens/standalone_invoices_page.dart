import 'dart:convert';
import 'dart:typed_data';

import 'package:flutter/material.dart';
import 'package:printing/printing.dart';
import 'package:share_plus/share_plus.dart';
import 'package:http/http.dart' as http;
import 'package:url_launcher/url_launcher.dart';

import '../core/formatters.dart';
import '../services/api_client.dart';
import '../services/pdf_download.dart';
import 'folio_page.dart';

String _prestationsDate(dynamic value) {
  final date = DateTime.tryParse(value?.toString() ?? '');
  if (date == null) return 'date inconnue';
  return '${date.day.toString().padLeft(2, '0')}-${date.month.toString().padLeft(2, '0')}-${date.year}';
}

class _InvoiceLineDraft {
  final description = TextEditingController();
  final amount = TextEditingController();
  final quantity = TextEditingController(text: '1');

  void dispose() {
    description.dispose();
    amount.dispose();
    quantity.dispose();
  }
}

class StandaloneInvoiceDetailPage extends StatefulWidget {
  const StandaloneInvoiceDetailPage({
    super.key,
    required this.invoice,
    required this.role,
    required this.userName,
  });

  final Map<String, dynamic> invoice;
  final String role;
  final String userName;

  @override
  State<StandaloneInvoiceDetailPage> createState() =>
      _StandaloneInvoiceDetailPageState();
}

class _StandaloneInvoiceDetailPageState
    extends State<StandaloneInvoiceDetailPage> {
  final _api = const ApiClient();
  late Map<String, dynamic> _invoice;
  bool _busy = false;

  @override
  void initState() {
    super.initState();
    _invoice = Map<String, dynamic>.from(widget.invoice);
  }

  List<Map<String, dynamic>> _list(String key) =>
      (_invoice[key] as List<dynamic>? ?? [])
          .whereType<Map>()
          .map((value) => Map<String, dynamic>.from(value))
          .toList();

  void _show(String message) => ScaffoldMessenger.of(
    context,
  ).showSnackBar(SnackBar(content: Text(message)));

  Future<void> _addItem() async {
    final description = TextEditingController();
    final amount = TextEditingController();
    final quantity = TextEditingController(text: '1');
    final values = await showDialog<List<String>>(
      context: context,
      builder: (context) => AlertDialog(
        title: const Text('Ajouter une prestation ou un extra'),
        content: Column(
          mainAxisSize: MainAxisSize.min,
          children: [
            TextField(
              controller: description,
              decoration: const InputDecoration(labelText: 'Description *'),
            ),
            TextField(
              controller: amount,
              keyboardType: TextInputType.number,
              decoration: const InputDecoration(
                labelText: 'Prix unitaire (Ar) *',
              ),
            ),
            TextField(
              controller: quantity,
              keyboardType: TextInputType.number,
              decoration: const InputDecoration(labelText: 'Quantité *'),
            ),
          ],
        ),
        actions: [
          TextButton(
            onPressed: () => Navigator.pop(context),
            child: const Text('Annuler'),
          ),
          FilledButton(
            onPressed: () => Navigator.pop(context, [
              description.text,
              amount.text,
              quantity.text,
            ]),
            child: const Text('Ajouter'),
          ),
        ],
      ),
    );
    description.dispose();
    amount.dispose();
    quantity.dispose();
    if (values == null ||
        values[0].trim().isEmpty ||
        int.tryParse(values[1]) == null)
      return;
    setState(() => _busy = true);
    final response = await _api
        .postJson('/api/standalone-invoices/${_invoice['id']}/items', {
          'description': values[0].trim(),
          'amount_ariary': int.parse(values[1]),
          'quantity': int.tryParse(values[2]) ?? 1,
          'actor_name': widget.userName,
          'actor_role': widget.role,
        });
    if (!mounted) return;
    setState(() => _busy = false);
    if (response.statusCode == 200) {
      setState(
        () => _invoice = Map<String, dynamic>.from(
          jsonDecode(response.body)['invoice'] as Map,
        ),
      );
    } else {
      _show('Ajout impossible.');
    }
  }

  Future<void> _addPayment() async {
    final amount = TextEditingController();
    final reference = TextEditingController();
    final formKey = GlobalKey<FormState>();
    String? operator;
    String method = 'Espèces';
    final values = await showDialog<List<String>>(
      context: context,
      builder: (context) => StatefulBuilder(
        builder: (context, setDialogState) => AlertDialog(
          title: const Text('Ajouter un paiement'),
          content: Form(
            key: formKey,
            child: Column(
              mainAxisSize: MainAxisSize.min,
              children: [
                TextFormField(
                  controller: amount,
                  inputFormatters: const [AriaryInputFormatter()],
                  validator: (value) => parseAriaryAmount(value) > 0
                      ? null
                      : 'Saisissez un montant positif.',
                  keyboardType: TextInputType.number,
                  decoration: const InputDecoration(
                    labelText: 'Montant (Ar) *',
                  ),
                ),
                DropdownButtonFormField<String>(
                  initialValue: method,
                  items:
                      const [
                            'Espèces',
                            'Carte Bancaire',
                            'Mobile Money',
                            'Chèque',
                            'Virement',
                          ]
                          .map(
                            (value) => DropdownMenuItem(
                              value: value,
                              child: Text(value),
                            ),
                          )
                          .toList(),
                  onChanged: (value) =>
                      setDialogState(() => method = value ?? method),
                  decoration: const InputDecoration(labelText: 'Méthode'),
                ),
                if (method == 'Mobile Money')
                  DropdownButtonFormField<String>(
                    initialValue: operator,
                    decoration: const InputDecoration(labelText: 'Opérateur *'),
                    items: const [
                      DropdownMenuItem(value: 'mvola', child: Text('MVola')),
                      DropdownMenuItem(
                        value: 'orange money',
                        child: Text('Orange Money'),
                      ),
                      DropdownMenuItem(
                        value: 'airtel money',
                        child: Text('Airtel Money'),
                      ),
                    ],
                    onChanged: (value) =>
                        setDialogState(() => operator = value),
                    validator: (value) =>
                        value == null ? 'Choisissez un opérateur.' : null,
                  ),
                TextFormField(
                  controller: reference,
                  maxLength: 120,
                  decoration: InputDecoration(
                    labelText:
                        ['Mobile Money', 'Carte Bancaire'].contains(method)
                        ? 'Référence *'
                        : 'Référence (facultative)',
                  ),
                  validator: (value) =>
                      ['Mobile Money', 'Carte Bancaire'].contains(method) &&
                          (value ?? '').trim().isEmpty
                      ? 'La référence est obligatoire.'
                      : null,
                ),
              ],
            ),
          ),
          actions: [
            TextButton(
              onPressed: () => Navigator.pop(context),
              child: const Text('Annuler'),
            ),
            FilledButton(
              onPressed: () {
                if (formKey.currentState!.validate()) {
                  Navigator.pop(context, [
                    parseAriaryAmount(amount.text).toString(),
                    method,
                    reference.text.trim(),
                    method == 'Mobile Money' ? operator! : '',
                  ]);
                }
              },
              child: const Text('Enregistrer'),
            ),
          ],
        ),
      ),
    );
    amount.dispose();
    reference.dispose();
    if (values == null ||
        int.tryParse(values[0]) == null ||
        int.parse(values[0]) < 1)
      return;
    setState(() => _busy = true);
    final response = await _api
        .postJson('/api/invoices/${_invoice['id']}/payments', {
          'amount_ariary': int.parse(values[0]),
          'payment_method': values[1],
          'reference': values[2].isEmpty ? null : values[2],
          'payment_operator': values[3].isEmpty ? null : values[3],
          'processed_by_name': widget.userName,
          'processed_by_role': widget.role,
        });
    if (!mounted) return;
    setState(() => _busy = false);
    if (response.statusCode == 200) {
      setState(
        () => _invoice = Map<String, dynamic>.from(
          jsonDecode(response.body)['invoice'] as Map,
        ),
      );
      _show(
        'Paiement enregistré. La facture normale est maintenant disponible.',
      );
    } else {
      _show('Paiement impossible.');
    }
  }

  Future<void> _updatePdf(String documentType) async {
    setState(() => _busy = true);
    final response = await _api.postJson(
      '/api/invoices/${_invoice['id']}/generate-pdf',
      {'document_type': documentType, 'actor_role': widget.role},
    );
    if (!mounted) return;
    setState(() => _busy = false);
    if (response.statusCode == 200) {
      final payload = jsonDecode(response.body)['invoice'];
      if (payload is Map)
        setState(() => _invoice = Map<String, dynamic>.from(payload));
      _show(
        documentType == 'proforma'
            ? 'Proforma mise à jour.'
            : 'Facture mise à jour.',
      );
    } else {
      _show('Mise à jour du PDF impossible.');
    }
  }

  Future<Uint8List> _pdfBytes() async {
    final url = _invoice['pdf_url']?.toString();
    if (url == null || url.isEmpty) throw Exception('Aucun PDF disponible.');
    final response = await http.get(Uri.parse(url));
    if (response.statusCode != 200)
      throw Exception('Téléchargement PDF impossible.');
    return response.bodyBytes;
  }

  Future<void> _previewPdf() async {
    try {
      final bytes = await _pdfBytes();
      if (!mounted) return;
      await Navigator.push(
        context,
        MaterialPageRoute(
          builder: (_) => InvoicePdfPage(
            title: _invoice['invoice_number']?.toString() ?? 'Facture',
            bytes: bytes,
          ),
        ),
      );
    } catch (error) {
      _show(error.toString());
    }
  }

  Future<void> _downloadPdf() async {
    try {
      final message = await savePdfToDownloads(
        await _pdfBytes(),
        '${_invoice['invoice_number'] ?? 'facture'}.pdf',
      );
      _show(message);
    } catch (error) {
      _show(error.toString());
    }
  }

  Future<void> _sharePdf() async {
    try {
      final bytes = await _pdfBytes();
      await SharePlus.instance.share(
        ShareParams(
          files: [
            XFile.fromData(
              bytes,
              name: '${_invoice['invoice_number'] ?? 'facture'}.pdf',
              mimeType: 'application/pdf',
            ),
          ],
          text: 'Facture ${_invoice['invoice_number'] ?? ''}',
        ),
      );
    } catch (error) {
      _show(error.toString());
    }
  }

  Future<void> _printPdf() async {
    try {
      final bytes = await _pdfBytes();
      await Printing.layoutPdf(onLayout: (_) async => bytes);
    } catch (error) {
      _show(error.toString());
    }
  }

  Future<void> _deleteItem(Map<String, dynamic> item) async {
    final confirmed = await showDialog<bool>(
      context: context,
      builder: (context) => AlertDialog(
        title: const Text('Supprimer cet extra ?'),
        content: Text(item['description']?.toString() ?? ''),
        actions: [
          TextButton(
            onPressed: () => Navigator.pop(context, false),
            child: const Text('Annuler'),
          ),
          FilledButton(
            onPressed: () => Navigator.pop(context, true),
            child: const Text('Supprimer'),
          ),
        ],
      ),
    );
    if (confirmed != true) return;
    setState(() => _busy = true);
    final response = await _api.deleteJson(
      '/api/invoices/${_invoice['id']}/items/${item['id']}',
      {'actor_name': widget.userName, 'actor_role': widget.role},
    );
    if (!mounted) return;
    setState(() => _busy = false);
    if (response.statusCode == 200) {
      _show('Extra supprimé.');
      final refreshed = await _api.get('/api/standalone-invoices', {
        'actor_role': widget.role,
      });
      if (refreshed.statusCode == 200) {
        final list = (jsonDecode(refreshed.body) as List).whereType<Map>().map(
          (e) => Map<String, dynamic>.from(e),
        );
        final found = list.firstWhere(
          (e) => e['id'] == _invoice['id'],
          orElse: () => <String, dynamic>{},
        );
        if (found.isNotEmpty) setState(() => _invoice = found);
      }
    } else {
      _show('Suppression impossible.');
    }
  }

  @override
  Widget build(BuildContext context) {
    final items = _list('items');
    final payments = _list('payments');
    final isProforma = _invoice['document_type'] == 'proforma';
    return Scaffold(
      appBar: AppBar(
        title: Text(isProforma ? 'Proforma' : 'Facture normale'),
        actions: [
          if ((_invoice['pdf_url'] ?? '').toString().isNotEmpty)
            IconButton(
              onPressed: () => launchUrl(
                Uri.parse(_invoice['pdf_url'].toString()),
                mode: LaunchMode.externalApplication,
              ),
              icon: const Icon(Icons.picture_as_pdf),
              tooltip: 'Voir le PDF',
            ),
        ],
      ),
      body: Row(
        children: [
          Expanded(
            child: AbsorbPointer(
              absorbing: _busy,
              child: ListView(
                padding: const EdgeInsets.all(24),
                children: [
                  Card(
                    child: Padding(
                      padding: const EdgeInsets.all(20),
                      child: Row(
                        mainAxisAlignment: MainAxisAlignment.spaceBetween,
                        children: [
                          Column(
                            crossAxisAlignment: CrossAxisAlignment.start,
                            children: [
                              Text(
                                isProforma ? 'FACTURE PROFORMA' : 'FACTURE',
                                style: const TextStyle(
                                  fontSize: 12,
                                  fontWeight: FontWeight.w800,
                                  color: Colors.teal,
                                ),
                              ),
                              const SizedBox(height: 4),
                              Text(
                                _invoice['invoice_number']?.toString() ??
                                    'Sans numéro',
                                style: const TextStyle(
                                  fontSize: 22,
                                  fontWeight: FontWeight.w900,
                                ),
                              ),
                              Text(
                                'Date de prestations : ${_prestationsDate(_invoice['issued_at'])}',
                              ),
                            ],
                          ),
                          Chip(
                            label: Text(
                              _invoice['status']?.toString() ?? 'open',
                            ),
                          ),
                        ],
                      ),
                    ),
                  ),
                  const SizedBox(height: 14),
                  Card(
                    child: Padding(
                      padding: const EdgeInsets.all(20),
                      child: Column(
                        crossAxisAlignment: CrossAxisAlignment.start,
                        children: [
                          const Text(
                            'Client',
                            style: TextStyle(fontWeight: FontWeight.w800),
                          ),
                          const SizedBox(height: 6),
                          Text(
                            _invoice['client_name']?.toString() ??
                                'Client non renseigné',
                            style: const TextStyle(
                              fontSize: 18,
                              fontWeight: FontWeight.w800,
                            ),
                          ),
                          Text(_invoice['client_phone']?.toString() ?? ''),
                          Text(_invoice['client_email']?.toString() ?? ''),
                        ],
                      ),
                    ),
                  ),
                  const SizedBox(height: 14),
                  Card(
                    child: Padding(
                      padding: const EdgeInsets.all(20),
                      child: Column(
                        crossAxisAlignment: CrossAxisAlignment.start,
                        children: [
                          const Text(
                            'Prestations et extras',
                            style: TextStyle(
                              fontSize: 18,
                              fontWeight: FontWeight.w800,
                            ),
                          ),
                          ...items.map(
                            (item) => ListTile(
                              contentPadding: EdgeInsets.zero,
                              title: Text(
                                item['description']?.toString() ?? '',
                              ),
                              subtitle: Text(
                                'Quantité : ${item['quantity'] ?? 1}',
                              ),
                              trailing: Row(
                                mainAxisSize: MainAxisSize.min,
                                children: [
                                  Text(
                                    '${formatPrice(item['amount_ariary'])} Ar',
                                  ),
                                  if (item['id'] != null)
                                    IconButton(
                                      onPressed: () => _deleteItem(item),
                                      icon: const Icon(
                                        Icons.delete_outline,
                                        color: Colors.red,
                                      ),
                                      tooltip: 'Supprimer l’extra',
                                    ),
                                ],
                              ),
                            ),
                          ),
                          const Divider(),
                          Align(
                            alignment: Alignment.centerRight,
                            child: Text(
                              'Total : ${formatPrice(_invoice['total_amount_ariary'])} Ar',
                              style: const TextStyle(
                                fontSize: 18,
                                fontWeight: FontWeight.w900,
                              ),
                            ),
                          ),
                        ],
                      ),
                    ),
                  ),
                  const SizedBox(height: 14),
                  Card(
                    child: Padding(
                      padding: const EdgeInsets.all(20),
                      child: Column(
                        crossAxisAlignment: CrossAxisAlignment.start,
                        children: [
                          const Text(
                            'Paiements',
                            style: TextStyle(
                              fontSize: 18,
                              fontWeight: FontWeight.w800,
                            ),
                          ),
                          if (payments.isEmpty)
                            const Padding(
                              padding: EdgeInsets.symmetric(vertical: 12),
                              child: Text('Aucun paiement enregistré.'),
                            ),
                          ...payments.map(
                            (payment) => ListTile(
                              contentPadding: EdgeInsets.zero,
                              title: Text(
                                payment['payment_method']?.toString() ?? '',
                              ),
                              subtitle: Text(
                                payment['created_at']?.toString() ?? '',
                              ),
                              trailing: Text(
                                '${formatPrice(payment['amount_ariary'])} Ar',
                              ),
                            ),
                          ),
                          Text(
                            'Total payé : ${formatPrice(_invoice['paid_amount_ariary'])} Ar',
                            style: const TextStyle(fontWeight: FontWeight.w800),
                          ),
                          Text(
                            'Reste à payer : ${formatPrice(_invoice['balance_amount_ariary'])} Ar',
                            style: const TextStyle(fontWeight: FontWeight.w900),
                          ),
                        ],
                      ),
                    ),
                  ),
                  const SizedBox(height: 18),
                  Wrap(
                    spacing: 12,
                    runSpacing: 12,
                    children: [
                      OutlinedButton.icon(
                        onPressed: _addItem,
                        icon: const Icon(Icons.add),
                        label: const Text('Ajouter un extra'),
                      ),
                      FilledButton.icon(
                        onPressed: _addPayment,
                        icon: const Icon(Icons.payments_outlined),
                        label: const Text('Ajouter un paiement'),
                      ),
                    ],
                  ),
                ],
              ),
            ),
          ),
          Container(
            width: 340,
            color: const Color(0xFFF4F7F6),
            padding: const EdgeInsets.all(24),
            child: ListView(
              children: [
                Row(
                  mainAxisAlignment: MainAxisAlignment.spaceBetween,
                  children: [
                    const Text(
                      'Paiements',
                      style: TextStyle(
                        fontSize: 17,
                        fontWeight: FontWeight.w800,
                      ),
                    ),
                    TextButton.icon(
                      onPressed: _addPayment,
                      icon: const Icon(Icons.add_card),
                      label: const Text('Paiement'),
                    ),
                  ],
                ),
                if (payments.isEmpty)
                  const Padding(
                    padding: EdgeInsets.symmetric(vertical: 18),
                    child: Text('Aucun paiement enregistré.'),
                  ),
                ...payments.map(
                  (payment) => ListTile(
                    contentPadding: EdgeInsets.zero,
                    title: Text(payment['payment_method']?.toString() ?? ''),
                    subtitle: Text(payment['created_at']?.toString() ?? ''),
                    trailing: Text(
                      '${formatPrice(payment['amount_ariary'])} Ar',
                    ),
                  ),
                ),
                const SizedBox(height: 16),
                SegmentedButton<String>(
                  selected: {isProforma ? 'proforma' : 'facture'},
                  segments: const [
                    ButtonSegment(
                      value: 'facture',
                      label: Text('Facture'),
                      icon: Icon(Icons.check),
                    ),
                    ButtonSegment(
                      value: 'proforma',
                      label: Text('Proforma'),
                      icon: Icon(Icons.description_outlined),
                    ),
                  ],
                  onSelectionChanged: (selection) =>
                      _updatePdf(selection.first),
                ),
                const SizedBox(height: 12),
                SizedBox(
                  width: double.infinity,
                  child: FilledButton.icon(
                    onPressed: () =>
                        _updatePdf(isProforma ? 'proforma' : 'facture'),
                    icon: const Icon(Icons.picture_as_pdf),
                    label: const Text('Mettre à jour le PDF'),
                  ),
                ),
                const SizedBox(height: 8),
                OutlinedButton.icon(
                  onPressed: _previewPdf,
                  icon: const Icon(Icons.visibility_outlined),
                  label: const Text('Visualiser'),
                ),
                OutlinedButton.icon(
                  onPressed: _downloadPdf,
                  icon: const Icon(Icons.download),
                  label: const Text('Télécharger la facture'),
                ),
                OutlinedButton.icon(
                  onPressed: _sharePdf,
                  icon: const Icon(Icons.share_outlined),
                  label: const Text('Partager'),
                ),
                OutlinedButton.icon(
                  onPressed: _printPdf,
                  icon: const Icon(Icons.print_outlined),
                  label: const Text('Imprimer'),
                ),
              ],
            ),
          ),
        ],
      ),
    );
  }
}

class StandaloneInvoicesPage extends StatefulWidget {
  const StandaloneInvoicesPage({
    super.key,
    required this.role,
    required this.userName,
  });

  final String role;
  final String userName;

  @override
  State<StandaloneInvoicesPage> createState() => _StandaloneInvoicesPageState();
}

class _StandaloneInvoicesPageState extends State<StandaloneInvoicesPage> {
  final _api = const ApiClient();
  final _client = TextEditingController();
  final _phone = TextEditingController();
  final _email = TextEditingController();
  final _organizationName = TextEditingController();
  final _organizationPhone = TextEditingController();
  final _organizationContact = TextEditingController();
  final _organizationAddress = TextEditingController();
  final _organizationNif = TextEditingController();
  final _organizationStat = TextEditingController();
  final _search = TextEditingController();
  final List<_InvoiceLineDraft> _lines = [_InvoiceLineDraft()];
  List<Map<String, dynamic>> _invoices = [];
  String _documentType = 'facture';
  bool _loading = true;
  bool _saving = false;
  bool _isOrganization = false;
  DateTime _invoiceDate = DateTime.now();

  @override
  void initState() {
    super.initState();
    _load();
  }

  @override
  void dispose() {
    for (final line in _lines) {
      line.dispose();
    }
    _client.dispose();
    _phone.dispose();
    _email.dispose();
    _organizationName.dispose();
    _organizationPhone.dispose();
    _organizationContact.dispose();
    _organizationAddress.dispose();
    _organizationNif.dispose();
    _organizationStat.dispose();
    _search.dispose();
    super.dispose();
  }

  Future<void> _load() async {
    setState(() => _loading = true);
    final response = await _api.get('/api/standalone-invoices', {
      'actor_role': widget.role,
    });
    if (!mounted) return;
    if (response.statusCode == 200) {
      final data = jsonDecode(response.body) as List<dynamic>;
      setState(
        () => _invoices = data
            .whereType<Map>()
            .map((e) => Map<String, dynamic>.from(e))
            .where(
              (invoice) => !['annule', 'cancelled'].contains(invoice['status']),
            )
            .toList(),
      );
    } else {
      _show('Impossible de charger les factures.');
    }
    setState(() => _loading = false);
  }

  Future<void> _create() async {
    final validLines = _lines
        .where(
          (line) =>
              line.description.text.trim().isNotEmpty &&
              int.tryParse(line.amount.text) != null,
        )
        .toList();
    final customerName = _client.text.trim();
    final phoneIsValid = _isOrganization
        ? _organizationPhone.text.trim().isNotEmpty
        : _phone.text.trim().isNotEmpty;
    if (customerName.isEmpty ||
        !phoneIsValid ||
        validLines.length != _lines.length) {
      _show('Renseigne le nom, le téléphone obligatoire et chaque prestation.');
      return;
    }
    setState(() => _saving = true);
    try {
      final created = await _api.postJson('/api/standalone-invoices', {
        'client_name': customerName,
        'client_phone':
            (_isOrganization ? _organizationContact.text : _phone.text).trim(),
        'client_email': _email.text.trim(),
        'customer_type': _isOrganization ? 'organisme' : 'particulier',
        'organization_name': _isOrganization ? customerName : '',
        'organization_phone': _organizationPhone.text.trim(),
        'organization_contact_name': _organizationContact.text.trim(),
        'organization_billing_address': _organizationAddress.text.trim(),
        'organization_nif': _organizationNif.text.trim(),
        'organization_stat': _organizationStat.text.trim(),
        'document_type': _documentType,
        'issued_at': _invoiceDate.toIso8601String().substring(0, 10),
        'actor_name': widget.userName,
        'actor_role': widget.role,
      });
      if (created.statusCode != 201) {
        _show('Création impossible.');
        return;
      }
      final invoice =
          jsonDecode(created.body)['invoice'] as Map<String, dynamic>;
      var allItemsSaved = true;
      for (final line in _lines) {
        final item = await _api
            .postJson('/api/standalone-invoices/${invoice['id']}/items', {
              'description': line.description.text.trim(),
              'amount_ariary': int.parse(line.amount.text),
              'quantity': int.tryParse(line.quantity.text) ?? 1,
              'actor_name': widget.userName,
              'actor_role': widget.role,
            });
        if (item.statusCode != 200) allItemsSaved = false;
      }
      if (allItemsSaved) {
        _client.clear();
        _phone.clear();
        _email.clear();
        _organizationName.clear();
        _organizationPhone.clear();
        _organizationContact.clear();
        _organizationAddress.clear();
        _organizationNif.clear();
        _organizationStat.clear();
        for (final line in _lines) {
          line.dispose();
        }
        _lines
          ..clear()
          ..add(_InvoiceLineDraft());
        _show('Facture créée.');
        await _load();
      } else {
        _show('La facture a été créée mais la ligne n’a pas pu être ajoutée.');
      }
    } finally {
      if (mounted) setState(() => _saving = false);
    }
  }

  void _show(String message) => ScaffoldMessenger.of(
    context,
  ).showSnackBar(SnackBar(content: Text(message)));

  Future<void> _showInvoice(Map<String, dynamic> invoice) async {
    var currentInvoice = Map<String, dynamic>.from(invoice);
    await showDialog<void>(
      context: context,
      builder: (context) => StatefulBuilder(
        builder: (context, setDialogState) {
          final items = (currentInvoice['items'] as List<dynamic>? ?? [])
              .whereType<Map>()
              .map((item) => Map<String, dynamic>.from(item))
              .toList();
          final payments = (currentInvoice['payments'] as List<dynamic>? ?? [])
              .whereType<Map>()
              .map((payment) => Map<String, dynamic>.from(payment))
              .toList();
          final issuedAt =
              currentInvoice['issued_at']?.toString() ?? 'Date inconnue';
          return AlertDialog(
            title: Text(
              currentInvoice['invoice_number']?.toString() ?? 'Facture',
            ),
            content: SizedBox(
              width: 620,
              child: SingleChildScrollView(
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Text(
                      currentInvoice['client_name']?.toString() ??
                          'Client non renseigné',
                      style: const TextStyle(fontWeight: FontWeight.w800),
                    ),
                    Text('Date de facture : $issuedAt'),
                    const SizedBox(height: 14),
                    const Text(
                      'Prestations / extras',
                      style: TextStyle(fontWeight: FontWeight.w800),
                    ),
                    ...items.map(
                      (item) => ListTile(
                        dense: true,
                        contentPadding: EdgeInsets.zero,
                        title: Text(item['description']?.toString() ?? ''),
                        subtitle: Text('Quantité : ${item['quantity'] ?? 1}'),
                        trailing: Text(
                          '${formatPrice(item['amount_ariary'])} Ar',
                        ),
                      ),
                    ),
                    const Divider(),
                    Text(
                      'Total : ${formatPrice(currentInvoice['total_amount_ariary'])} Ar',
                      style: const TextStyle(fontWeight: FontWeight.w900),
                    ),
                    const SizedBox(height: 12),
                    const Text(
                      'Paiements',
                      style: TextStyle(fontWeight: FontWeight.w800),
                    ),
                    ...payments.map(
                      (payment) => ListTile(
                        dense: true,
                        contentPadding: EdgeInsets.zero,
                        title: Text(
                          payment['payment_method']?.toString() ?? '',
                        ),
                        subtitle: Text(payment['created_at']?.toString() ?? ''),
                        trailing: Text(
                          '${formatPrice(payment['amount_ariary'])} Ar',
                        ),
                      ),
                    ),
                    Text(
                      'Payé : ${formatPrice(currentInvoice['paid_amount_ariary'])} Ar · Reste : ${formatPrice(currentInvoice['balance_amount_ariary'])} Ar',
                      style: const TextStyle(fontWeight: FontWeight.w800),
                    ),
                  ],
                ),
              ),
            ),
            actions: [
              TextButton.icon(
                onPressed: () async {
                  final result = await _addStandaloneItem(currentInvoice);
                  if (result != null)
                    setDialogState(() => currentInvoice = result);
                },
                icon: const Icon(Icons.add),
                label: const Text('Ajouter un extra'),
              ),
              TextButton.icon(
                onPressed: () async {
                  final result = await _addStandalonePayment(currentInvoice);
                  if (result != null)
                    setDialogState(() => currentInvoice = result);
                },
                icon: const Icon(Icons.payments_outlined),
                label: const Text('Ajouter un paiement'),
              ),
              if ((currentInvoice['pdf_url'] ?? '').toString().isNotEmpty)
                TextButton.icon(
                  onPressed: () => launchUrl(
                    Uri.parse(currentInvoice['pdf_url'].toString()),
                    mode: LaunchMode.externalApplication,
                  ),
                  icon: const Icon(Icons.picture_as_pdf),
                  label: const Text('Voir la facture PDF'),
                ),
              TextButton(
                onPressed: () => Navigator.pop(context),
                child: const Text('Fermer'),
              ),
            ],
          );
        },
      ),
    );
    await _load();
  }

  Future<Map<String, dynamic>?> _addStandaloneItem(
    Map<String, dynamic> invoice,
  ) async {
    final description = TextEditingController();
    final amount = TextEditingController();
    final quantity = TextEditingController(text: '1');
    final values = await showDialog<List<String>>(
      context: context,
      builder: (context) => AlertDialog(
        title: const Text('Ajouter une prestation ou un extra'),
        content: Column(
          mainAxisSize: MainAxisSize.min,
          children: [
            TextField(
              controller: description,
              decoration: const InputDecoration(labelText: 'Description *'),
            ),
            TextField(
              controller: amount,
              keyboardType: TextInputType.number,
              decoration: const InputDecoration(
                labelText: 'Prix unitaire (Ar) *',
              ),
            ),
            TextField(
              controller: quantity,
              keyboardType: TextInputType.number,
              decoration: const InputDecoration(labelText: 'Quantité *'),
            ),
          ],
        ),
        actions: [
          TextButton(
            onPressed: () => Navigator.pop(context),
            child: const Text('Annuler'),
          ),
          FilledButton(
            onPressed: () => Navigator.pop(context, [
              description.text,
              amount.text,
              quantity.text,
            ]),
            child: const Text('Ajouter'),
          ),
        ],
      ),
    );
    description.dispose();
    amount.dispose();
    quantity.dispose();
    if (values == null ||
        values[0].trim().isEmpty ||
        int.tryParse(values[1]) == null)
      return null;
    final response = await _api
        .postJson('/api/standalone-invoices/${invoice['id']}/items', {
          'description': values[0].trim(),
          'amount_ariary': int.parse(values[1]),
          'quantity': int.tryParse(values[2]) ?? 1,
          'actor_name': widget.userName,
          'actor_role': widget.role,
        });
    if (response.statusCode != 200) {
      _show('Ajout impossible.');
      return null;
    }
    return Map<String, dynamic>.from(
      jsonDecode(response.body)['invoice'] as Map,
    );
  }

  Future<Map<String, dynamic>?> _addStandalonePayment(
    Map<String, dynamic> invoice,
  ) async {
    final amount = TextEditingController();
    String method = 'Espèces';
    final values = await showDialog<List<String>>(
      context: context,
      builder: (context) => StatefulBuilder(
        builder: (context, setState) => AlertDialog(
          title: const Text('Ajouter un paiement'),
          content: Column(
            mainAxisSize: MainAxisSize.min,
            children: [
              TextField(
                controller: amount,
                keyboardType: TextInputType.number,
                decoration: const InputDecoration(labelText: 'Montant (Ar) *'),
              ),
              DropdownButtonFormField<String>(
                initialValue: method,
                items:
                    const [
                          'Espèces',
                          'Carte Bancaire',
                          'Mobile Money',
                          'Chèque',
                          'Virement',
                        ]
                        .map(
                          (value) => DropdownMenuItem(
                            value: value,
                            child: Text(value),
                          ),
                        )
                        .toList(),
                onChanged: (value) => setState(() => method = value ?? method),
                decoration: const InputDecoration(labelText: 'Méthode'),
              ),
            ],
          ),
          actions: [
            TextButton(
              onPressed: () => Navigator.pop(context),
              child: const Text('Annuler'),
            ),
            FilledButton(
              onPressed: () => Navigator.pop(context, [amount.text, method]),
              child: const Text('Enregistrer'),
            ),
          ],
        ),
      ),
    );
    amount.dispose();
    if (values == null ||
        int.tryParse(values[0]) == null ||
        int.parse(values[0]) < 1)
      return null;
    final response = await _api
        .postJson('/api/invoices/${invoice['id']}/payments', {
          'amount_ariary': int.parse(values[0]),
          'payment_method': values[1],
          'processed_by_name': widget.userName,
          'processed_by_role': widget.role,
        });
    if (response.statusCode != 200) {
      _show('Paiement impossible.');
      return null;
    }
    return Map<String, dynamic>.from(
      jsonDecode(response.body)['invoice'] as Map,
    );
  }

  List<Map<String, dynamic>> get _filteredInvoices {
    final query = _search.text.trim().toLowerCase();
    if (query.isEmpty) return _invoices;
    return _invoices.where((invoice) {
      final text =
          '${invoice['client_name'] ?? ''} ${invoice['invoice_number'] ?? ''} ${invoice['document_type'] ?? ''}'
              .toLowerCase();
      return text.contains(query);
    }).toList();
  }

  Future<void> _cancelInvoice(Map<String, dynamic> invoice) async {
    final confirmed = await showDialog<bool>(
      context: context,
      builder: (context) => AlertDialog(
        title: const Text('Annuler la facture ?'),
        content: Text(
          'La facture ${invoice['invoice_number'] ?? ''} sera exclue du chiffre d’affaires.',
        ),
        actions: [
          TextButton(
            onPressed: () => Navigator.pop(context, false),
            child: const Text('Non'),
          ),
          FilledButton(
            onPressed: () => Navigator.pop(context, true),
            child: const Text('Annuler la facture'),
          ),
        ],
      ),
    );
    if (confirmed != true) return;
    final response = await _api.postJson(
      '/api/standalone-invoices/${invoice['id']}/cancel',
      {'actor_name': widget.userName, 'actor_role': widget.role},
    );
    if (!mounted) return;
    _show(
      response.statusCode == 200
          ? 'Facture annulée.'
          : 'Annulation impossible.',
    );
    if (response.statusCode == 200) await _load();
  }

  void _addLine() => setState(() => _lines.add(_InvoiceLineDraft()));

  void _removeLine(int index) {
    if (_lines.length == 1) return;
    setState(() {
      _lines[index].dispose();
      _lines.removeAt(index);
    });
  }

  Future<void> _selectInvoiceDate() async {
    final selected = await showDatePicker(
      context: context,
      initialDate: _invoiceDate,
      firstDate: DateTime(2000),
      lastDate: DateTime(2100),
    );
    if (selected != null && mounted) {
      setState(() => _invoiceDate = selected);
    }
  }

  @override
  Widget build(BuildContext context) {
    if (!['admin', 'superadmin', 'receptionist'].contains(widget.role)) {
      return const Scaffold(
        body: Center(child: Text('Accès réservé aux administrateurs.')),
      );
    }
    return Scaffold(
      appBar: AppBar(title: const Text('Facturation libre')),
      body: RefreshIndicator(
        onRefresh: _load,
        child: ListView(
          padding: const EdgeInsets.all(24),
          children: [
            if (widget.role != 'receptionist')
              Card(
                child: Padding(
                  padding: const EdgeInsets.all(18),
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                      const Text(
                        'Nouvelle facture',
                        style: TextStyle(
                          fontSize: 20,
                          fontWeight: FontWeight.w800,
                        ),
                      ),
                      const SizedBox(height: 14),
                      Wrap(
                        spacing: 12,
                        runSpacing: 12,
                        children: [
                          SizedBox(
                            width: 260,
                            child: TextField(
                              controller: _client,
                              decoration: InputDecoration(
                                labelText: _isOrganization
                                    ? 'Nom de l’organisme *'
                                    : 'Nom du client *',
                              ),
                            ),
                          ),
                          SizedBox(
                            width: 220,
                            child: DropdownButtonFormField<bool>(
                              initialValue: _isOrganization,
                              decoration: const InputDecoration(
                                labelText: 'Type de client *',
                              ),
                              items: const [
                                DropdownMenuItem(
                                  value: false,
                                  child: Text('Particulier'),
                                ),
                                DropdownMenuItem(
                                  value: true,
                                  child: Text('Organisme'),
                                ),
                              ],
                              onChanged: (value) => setState(
                                () => _isOrganization = value ?? false,
                              ),
                            ),
                          ),
                          if (_isOrganization) ...[
                            SizedBox(
                              width: 260,
                              child: TextField(
                                controller: _organizationAddress,
                                decoration: const InputDecoration(
                                  labelText: 'Adresse de facturation',
                                ),
                              ),
                            ),
                            SizedBox(
                              width: 220,
                              child: TextField(
                                controller: _organizationPhone,
                                decoration: const InputDecoration(
                                  labelText: 'Téléphone organisme *',
                                ),
                              ),
                            ),
                            SizedBox(
                              width: 240,
                              child: TextField(
                                controller: _organizationContact,
                                decoration: const InputDecoration(
                                  labelText: 'Téléphone personne à contacter',
                                ),
                              ),
                            ),
                            SizedBox(
                              width: 160,
                              child: TextField(
                                controller: _organizationNif,
                                decoration: const InputDecoration(
                                  labelText: 'NIF',
                                ),
                              ),
                            ),
                            SizedBox(
                              width: 160,
                              child: TextField(
                                controller: _organizationStat,
                                decoration: const InputDecoration(
                                  labelText: 'STAT',
                                ),
                              ),
                            ),
                          ],
                          if (!_isOrganization)
                            SizedBox(
                              width: 220,
                              child: TextField(
                                controller: _phone,
                                decoration: const InputDecoration(
                                  labelText: 'Téléphone *',
                                ),
                              ),
                            ),
                          SizedBox(
                            width: 260,
                            child: TextField(
                              controller: _email,
                              decoration: const InputDecoration(
                                labelText: 'Email',
                              ),
                            ),
                          ),
                          SizedBox(
                            width: 220,
                            child: DropdownButtonFormField<String>(
                              initialValue: _documentType,
                              decoration: const InputDecoration(
                                labelText: 'Type de document',
                              ),
                              items: const [
                                DropdownMenuItem(
                                  value: 'facture',
                                  child: Text('Facture normale'),
                                ),
                                DropdownMenuItem(
                                  value: 'proforma',
                                  child: Text('Facture proforma'),
                                ),
                              ],
                              onChanged: (v) => setState(
                                () => _documentType = v ?? 'facture',
                              ),
                            ),
                          ),
                          SizedBox(
                            width: 220,
                            child: InkWell(
                              onTap: _selectInvoiceDate,
                              borderRadius: BorderRadius.circular(8),
                              child: InputDecorator(
                                decoration: const InputDecoration(
                                  labelText: 'Date de prestations',
                                  prefixIcon: Icon(Icons.calendar_today),
                                ),
                                child: Text(
                                  '${_invoiceDate.day.toString().padLeft(2, '0')}/${_invoiceDate.month.toString().padLeft(2, '0')}/${_invoiceDate.year}',
                                ),
                              ),
                            ),
                          ),
                          ..._lines.asMap().entries.map((entry) {
                            final index = entry.key;
                            final line = entry.value;
                            return Row(
                              mainAxisSize: MainAxisSize.min,
                              children: [
                                SizedBox(
                                  width: 260,
                                  child: TextField(
                                    controller: line.description,
                                    decoration: InputDecoration(
                                      labelText: 'Prestation ${index + 1} *',
                                    ),
                                  ),
                                ),
                                const SizedBox(width: 8),
                                SizedBox(
                                  width: 150,
                                  child: TextField(
                                    controller: line.amount,
                                    keyboardType: TextInputType.number,
                                    decoration: const InputDecoration(
                                      labelText: 'Prix unitaire (Ar) *',
                                    ),
                                  ),
                                ),
                                const SizedBox(width: 8),
                                SizedBox(
                                  width: 80,
                                  child: TextField(
                                    controller: line.quantity,
                                    keyboardType: TextInputType.number,
                                    decoration: const InputDecoration(
                                      labelText: 'Qté',
                                    ),
                                  ),
                                ),
                                IconButton(
                                  onPressed: () => _removeLine(index),
                                  icon: const Icon(Icons.remove_circle_outline),
                                  tooltip: 'Supprimer la prestation',
                                ),
                              ],
                            );
                          }),
                        ],
                      ),
                      const SizedBox(height: 16),
                      Wrap(
                        spacing: 10,
                        runSpacing: 10,
                        children: [
                          OutlinedButton.icon(
                            onPressed: _saving ? null : _addLine,
                            icon: const Icon(Icons.add),
                            label: const Text('Ajouter une prestation'),
                          ),
                          FilledButton.icon(
                            onPressed: _saving ? null : _create,
                            icon: const Icon(Icons.receipt_long),
                            label: Text(
                              _saving ? 'Création...' : 'Créer la facture',
                            ),
                          ),
                        ],
                      ),
                    ],
                  ),
                ),
              ),
            const SizedBox(height: 22),
            const Text(
              'Factures indépendantes récentes',
              style: TextStyle(fontSize: 18, fontWeight: FontWeight.w800),
            ),
            const SizedBox(height: 8),
            TextField(
              controller: _search,
              onChanged: (_) => setState(() {}),
              decoration: const InputDecoration(
                labelText: 'Rechercher une facture',
                hintText: 'Client, numéro ou type de document',
                prefixIcon: Icon(Icons.search),
              ),
            ),
            const SizedBox(height: 10),
            if (_loading)
              const Center(
                child: Padding(
                  padding: EdgeInsets.all(24),
                  child: CircularProgressIndicator(),
                ),
              ),
            ..._filteredInvoices.map(
              (invoice) => Card(
                child: ListTile(
                  onTap: () async {
                    await Navigator.push<void>(
                      context,
                      MaterialPageRoute(
                        builder: (_) => StandaloneInvoiceDetailPage(
                          invoice: invoice,
                          role: widget.role,
                          userName: widget.userName,
                        ),
                      ),
                    );
                    if (mounted) await _load();
                  },
                  leading: Icon(
                    invoice['document_type'] == 'proforma'
                        ? Icons.description_outlined
                        : Icons.receipt_long,
                    color: Colors.teal,
                  ),
                  title: Text(
                    '${invoice['client_name']} — ${invoice['document_type'] == 'proforma' ? 'Proforma' : 'Facture'}',
                  ),
                  subtitle: Text(
                    '${invoice['status']} · ${formatPrice(invoice['total_amount_ariary'])} Ar\nGénérée le ${_prestationsDate(invoice['created_at'])}\nDate de prestations : ${_prestationsDate(invoice['issued_at'])}',
                  ),
                  trailing: invoice['status'] == 'annule'
                      ? const Chip(label: Text('Annulée'))
                      : IconButton(
                          icon: const Icon(
                            Icons.cancel_outlined,
                            color: Colors.red,
                          ),
                          tooltip: 'Annuler la facture',
                          onPressed: () => _cancelInvoice(invoice),
                        ),
                  titleTextStyle: const TextStyle(fontWeight: FontWeight.w700),
                ),
              ),
            ),
          ],
        ),
      ),
    );
  }
}
