import 'dart:async';

import 'package:flutter/material.dart';

import '../models/client_profile.dart';
import '../services/client_search_service.dart';

class ClientAutocompleteField extends StatefulWidget {
  const ClientAutocompleteField({
    super.key,
    required this.controller,
    required this.labelText,
    required this.prefixIcon,
    required this.onSelected,
    required this.valueBuilder,
    this.focusNode,
    this.validator,
    this.keyboardType,
    this.hintText,
    this.textInputAction,
    this.enabled = true,
  });

  final TextEditingController controller;
  final String labelText;
  final String? hintText;
  final IconData prefixIcon;
  final FocusNode? focusNode;
  final TextInputType? keyboardType;
  final FormFieldValidator<String>? validator;
  final TextInputAction? textInputAction;
  final bool enabled;
  final String Function(ClientProfile client) valueBuilder;
  final ValueChanged<ClientProfile> onSelected;

  @override
  State<ClientAutocompleteField> createState() =>
      _ClientAutocompleteFieldState();
}

class _ClientAutocompleteFieldState extends State<ClientAutocompleteField> {
  final ClientSearchService _searchService = ClientSearchService();
  Timer? _debounceTimer;
  Timer? _clearTimer;
  List<ClientProfile> _suggestions = [];
  bool _isSearching = false;
  bool _ignoreNextChange = false;
  final Object _tapRegionGroupId = Object();
  late FocusNode _focusNode;
  bool _ownsFocusNode = false;

  @override
  void initState() {
    super.initState();
    _ownsFocusNode = widget.focusNode == null;
    _focusNode = widget.focusNode ?? FocusNode();
    widget.controller.addListener(_handleTextChanged);
    _focusNode.addListener(_handleFocusChanged);
  }

  @override
  void didUpdateWidget(covariant ClientAutocompleteField oldWidget) {
    super.didUpdateWidget(oldWidget);
    if (oldWidget.controller != widget.controller) {
      oldWidget.controller.removeListener(_handleTextChanged);
      widget.controller.addListener(_handleTextChanged);
    }
  }

  @override
  void dispose() {
    _debounceTimer?.cancel();
    _clearTimer?.cancel();
    widget.controller.removeListener(_handleTextChanged);
    _focusNode.removeListener(_handleFocusChanged);
    if (_ownsFocusNode) {
      _focusNode.dispose();
    }
    super.dispose();
  }

  void _handleFocusChanged() {
    if (!_focusNode.hasFocus) {
      _clearTimer?.cancel();
      _clearTimer = Timer(const Duration(milliseconds: 180), () {
        if (mounted && !_focusNode.hasFocus) {
          setState(() {
            _suggestions = const [];
            _isSearching = false;
          });
        }
      });
      return;
    }

    _clearTimer?.cancel();
    _scheduleSearch(widget.controller.text);
  }

  void _handleTextChanged() {
    if (_ignoreNextChange) {
      _ignoreNextChange = false;
      return;
    }

    if (!_focusNode.hasFocus) {
      return;
    }

    _scheduleSearch(widget.controller.text);
  }

  void _scheduleSearch(String query) {
    _debounceTimer?.cancel();
    final term = query.trim();

    if (term.length < 2) {
      if (mounted) {
        setState(() {
          _suggestions = const [];
          _isSearching = false;
        });
      }
      return;
    }

    _debounceTimer = Timer(const Duration(milliseconds: 250), () async {
      if (!mounted) return;
      setState(() => _isSearching = true);
      final results = await _searchService.search(term);
      if (!mounted || widget.controller.text.trim() != term) return;
      setState(() {
        _suggestions = results;
        _isSearching = false;
      });
    });
  }

  void _select(ClientProfile client) {
    final value = widget.valueBuilder(client);
    _ignoreNextChange = true;
    widget.controller.value = TextEditingValue(
      text: value,
      selection: TextSelection.collapsed(offset: value.length),
    );
    setState(() => _suggestions = const []);
    widget.onSelected(client);
    FocusScope.of(context).unfocus();
  }

  @override
  Widget build(BuildContext context) {
    final hasSuggestions =
        _focusNode.hasFocus && (_isSearching || _suggestions.isNotEmpty);

    return TextFieldTapRegion(
      groupId: _tapRegionGroupId,
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          TextFormField(
            controller: widget.controller,
            focusNode: _focusNode,
            enabled: widget.enabled,
            keyboardType: widget.keyboardType,
            textInputAction: widget.textInputAction,
            validator: widget.validator,
            decoration: InputDecoration(
              labelText: widget.labelText,
              hintText: widget.hintText,
              prefixIcon: Icon(widget.prefixIcon),
              suffixIcon: _isSearching
                  ? const Padding(
                      padding: EdgeInsets.all(12),
                      child: SizedBox(
                        width: 16,
                        height: 16,
                        child: CircularProgressIndicator(strokeWidth: 2),
                      ),
                    )
                  : null,
            ),
          ),
          if (hasSuggestions)
            Material(
              elevation: 8,
              color: Colors.white,
              borderRadius: BorderRadius.circular(12),
              child: ConstrainedBox(
                constraints: const BoxConstraints(maxHeight: 240),
                child: ListView.separated(
                  shrinkWrap: true,
                  padding: const EdgeInsets.symmetric(vertical: 8),
                  itemCount: _suggestions.length,
                  separatorBuilder: (_, __) => const Divider(height: 1),
                  itemBuilder: (context, index) {
                    final client = _suggestions[index];
                    return ListTile(
                      dense: true,
                      leading: const Icon(Icons.badge_outlined),
                      title: Text(client.searchLabel),
                      subtitle: Text(
                        [
                          if ((client.phoneNumber ?? '').trim().isNotEmpty)
                            'Tél: ${client.phoneNumber}',
                          if (client.loyaltyCount > 0)
                            'Fidélité: ${client.loyaltyCount} visite(s)',
                        ].join(' • '),
                      ),
                      onTap: () => _select(client),
                    );
                  },
                ),
              ),
            ),
        ],
      ),
    );
  }
}
