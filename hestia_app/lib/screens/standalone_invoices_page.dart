import 'dart:convert';

import 'package:flutter/material.dart';
import 'package:url_launcher/url_launcher.dart';

import '../core/formatters.dart';
import '../services/api_client.dart';

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
    final customerName = _isOrganization
        ? _organizationName.text.trim()
        : _client.text.trim();
    if (customerName.isEmpty || validLines.length != _lines.length) {
      _show('Renseigne le client, chaque prestation et un montant valide.');
      return;
    }
    setState(() => _saving = true);
    try {
      final created = await _api.postJson('/api/standalone-invoices', {
        'client_name': customerName,
        'client_phone': _phone.text.trim(),
        'client_email': _email.text.trim(),
        'customer_type': _isOrganization ? 'organisme' : 'particulier',
        'organization_name': _organizationName.text.trim(),
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
    final items = (invoice['items'] as List<dynamic>? ?? [])
        .whereType<Map>()
        .map((item) => Map<String, dynamic>.from(item))
        .toList();
    await showDialog<void>(
      context: context,
      builder: (context) => AlertDialog(
        title: Text(invoice['invoice_number']?.toString() ?? 'Facture'),
        content: SizedBox(
          width: 520,
          child: ListView(
            shrinkWrap: true,
            children: [
              Text(
                invoice['client_name']?.toString() ?? 'Client non renseigné',
                style: const TextStyle(fontWeight: FontWeight.w800),
              ),
              const SizedBox(height: 12),
              ...items.map(
                (item) => ListTile(
                  dense: true,
                  contentPadding: EdgeInsets.zero,
                  title: Text(item['description']?.toString() ?? ''),
                  subtitle: Text('Quantité : ${item['quantity'] ?? 1}'),
                  trailing: Text(
                    '${formatPrice((item['amount_ariary'] as num?)?.toInt() ?? 0)} Ar',
                  ),
                ),
              ),
              const Divider(),
              Align(
                alignment: Alignment.centerRight,
                child: Text(
                  'Total : ${formatPrice(invoice['total_amount_ariary'])} Ar',
                  style: const TextStyle(fontWeight: FontWeight.w900),
                ),
              ),
            ],
          ),
        ),
        actions: [
          if ((invoice['pdf_url'] ?? '').toString().isNotEmpty)
            TextButton.icon(
              onPressed: () => launchUrl(
                Uri.parse(invoice['pdf_url'].toString()),
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
      ),
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
    if (!['admin', 'superadmin'].contains(widget.role)) {
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
                            decoration: const InputDecoration(
                              labelText: 'Client *',
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
                              controller: _organizationName,
                              decoration: const InputDecoration(
                                labelText: 'Nom de l’organisme *',
                              ),
                            ),
                          ),
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
                                labelText: 'Téléphone organisme',
                              ),
                            ),
                          ),
                          SizedBox(
                            width: 240,
                            child: TextField(
                              controller: _organizationContact,
                              decoration: const InputDecoration(
                                labelText: 'Nom du contact',
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
                        ] else
                          SizedBox(
                            width: 260,
                            child: TextField(
                              controller: _client,
                              decoration: const InputDecoration(
                                labelText: 'Nom du client *',
                              ),
                            ),
                          ),
                        SizedBox(
                          width: 220,
                          child: TextField(
                            controller: _phone,
                            decoration: const InputDecoration(
                              labelText: 'Téléphone',
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
                            onChanged: (v) =>
                                setState(() => _documentType = v ?? 'facture'),
                          ),
                        ),
                        SizedBox(
                          width: 220,
                          child: InkWell(
                            onTap: _selectInvoiceDate,
                            borderRadius: BorderRadius.circular(8),
                            child: InputDecorator(
                              decoration: const InputDecoration(
                                labelText: 'Date de facture',
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
                  onTap: () => _showInvoice(invoice),
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
                    '${invoice['status']} · ${formatPrice(invoice['total_amount_ariary'])} Ar\nGénérée le ${invoice['issued_at'] ?? 'date inconnue'}',
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
