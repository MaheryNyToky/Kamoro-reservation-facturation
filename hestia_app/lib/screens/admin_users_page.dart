import 'dart:convert';
import 'dart:math';

import 'package:flutter/material.dart';
import 'package:http/http.dart' as http;
import 'package:shared_preferences/shared_preferences.dart';

import '../core/app_config.dart';

const String baseUrl = AppConfig.apiBaseUrl;
const Color _primary = Color(0xFF0F766E);

class AdminUsersPage extends StatefulWidget {
  const AdminUsersPage({super.key, this.currentRole = 'admin'});

  final String currentRole;

  @override
  State<AdminUsersPage> createState() => _AdminUsersPageState();
}

class _AdminUsersPageState extends State<AdminUsersPage> {
  List<dynamic> _users = [];
  bool _isLoading = true;
  final Random _random = Random.secure();

  bool get _isSuperadmin => widget.currentRole == 'superadmin';
  bool get _isPrivileged => widget.currentRole != 'receptionist';

  List<String> get _allowedRoles {
    if (_isSuperadmin) {
      return const ['superadmin', 'admin', 'receptionist'];
    }
    return const ['admin', 'receptionist'];
  }

  String _roleLabel(String role) {
    switch (role) {
      case 'superadmin':
        return 'Super administrateur';
      case 'admin':
        return 'Administrateur';
      default:
        return 'Réceptionniste';
    }
  }

  bool _canManageUser(Map<String, dynamic> user) {
    if (!_isPrivileged) return false;
    if (_isSuperadmin) return true;
    return user['role']?.toString() != 'superadmin';
  }

  String _staffEmailFromName(String fullName) {
    final normalized = _asciiFold(
      fullName.toLowerCase(),
    ).trim().replaceAll(RegExp(r'[^a-z0-9]+'), ' ').trim();
    if (normalized.isEmpty) {
      return '';
    }

    final localPart = normalized.split(RegExp(r'\s+')).join('.');
    return '$localPart@kamorohotel.com';
  }

  String _generateStaffPassword() {
    return _random.nextInt(1000000).toString().padLeft(6, '0');
  }

  String _asciiFold(String input) {
    var value = input;
    const replacements = <String, String>{
      'À': 'A',
      'Á': 'A',
      'Â': 'A',
      'Ä': 'A',
      'Ã': 'A',
      'Å': 'A',
      'Æ': 'AE',
      'Ç': 'C',
      'È': 'E',
      'É': 'E',
      'Ê': 'E',
      'Ë': 'E',
      'Ì': 'I',
      'Í': 'I',
      'Î': 'I',
      'Ï': 'I',
      'Ñ': 'N',
      'Ò': 'O',
      'Ó': 'O',
      'Ô': 'O',
      'Ö': 'O',
      'Õ': 'O',
      'Œ': 'OE',
      'Ù': 'U',
      'Ú': 'U',
      'Û': 'U',
      'Ü': 'U',
      'Ý': 'Y',
      'à': 'a',
      'á': 'a',
      'â': 'a',
      'ä': 'a',
      'ã': 'a',
      'å': 'a',
      'æ': 'ae',
      'ç': 'c',
      'è': 'e',
      'é': 'e',
      'ê': 'e',
      'ë': 'e',
      'ì': 'i',
      'í': 'i',
      'î': 'i',
      'ï': 'i',
      'ñ': 'n',
      'ò': 'o',
      'ó': 'o',
      'ô': 'o',
      'ö': 'o',
      'õ': 'o',
      'œ': 'oe',
      'ù': 'u',
      'ú': 'u',
      'û': 'u',
      'ü': 'u',
      'ý': 'y',
      'ÿ': 'y',
    };

    replacements.forEach((from, to) {
      value = value.replaceAll(from, to);
    });

    return value;
  }

  @override
  void initState() {
    super.initState();
    _fetchUsers();
  }

  Future<void> _fetchUsers() async {
    if (mounted) {
      setState(() => _isLoading = true);
    }
    const cacheKey = 'admin_users_cache';
    try {
      final response = await http
          .get(Uri.parse('$baseUrl/api/users'))
          .timeout(const Duration(seconds: 5));
      if (!mounted) return;
      if (response.statusCode == 200) {
        final data = json.decode(response.body);
        final prefs = await SharedPreferences.getInstance();
        await prefs.setString(cacheKey, json.encode(data));
        if (!mounted) return;
        setState(() => _users = data);
      }
    } catch (e) {
      final prefs = await SharedPreferences.getInstance();
      final cached = prefs.getString(cacheKey);
      if (!mounted) return;
      if (cached != null) {
        setState(() => _users = json.decode(cached));
        ScaffoldMessenger.of(context).showSnackBar(
          const SnackBar(
            content: Text(
              'Mode dégradé: liste du personnel depuis le cache local.',
            ),
            backgroundColor: Colors.orange,
          ),
        );
      } else {
        debugPrint("Error fetching users: $e");
      }
    }
    if (mounted) {
      setState(() => _isLoading = false);
    }
  }

  Future<void> _deleteUser(dynamic id) async {
    try {
      final response = await http.delete(
        Uri.parse('$baseUrl/api/users/$id'),
        headers: const {'Content-Type': 'application/json'},
        body: json.encode({'actor_role': widget.currentRole}),
      );
      if (response.statusCode == 200) {
        _fetchUsers();
      }
    } catch (e) {
      debugPrint("Error deleting user: $e");
    }
  }

  Future<void> _showUserForm({Map<String, dynamic>? user}) async {
    final nameController = TextEditingController(text: user?['name'] ?? '');
    final emailController = TextEditingController(text: user?['email'] ?? '');
    final passwordController = TextEditingController();
    var obscurePassword = true;
    final initialRole = user?['role']?.toString();
    String role = _allowedRoles.contains(initialRole)
        ? initialRole!
        : (_isSuperadmin ? 'superadmin' : 'receptionist');
    final initialGeneratedEmail = _staffEmailFromName(nameController.text);
    if (user == null && initialGeneratedEmail.isNotEmpty) {
      emailController.text = initialGeneratedEmail;
    }

    void syncEmailFromName() {
      if (user != null && emailController.text.trim().isNotEmpty) {
        return;
      }

      final generatedEmail = _staffEmailFromName(nameController.text);
      if (generatedEmail.isEmpty || emailController.text == generatedEmail) {
        return;
      }

      emailController.text = generatedEmail;
    }

    nameController.addListener(syncEmailFromName);

    void applyGeneratedPassword() {
      passwordController.text = _generateStaffPassword();
      obscurePassword = false;
    }

    try {
      await showDialog<void>(
        context: context,
        builder: (context) {
          return StatefulBuilder(
            builder: (context, setModalState) {
              return AlertDialog(
                title: Text(
                  user == null ? 'Ajouter un personnel' : 'Modifier le profil',
                  style: const TextStyle(fontWeight: FontWeight.bold),
                ),
                content: SingleChildScrollView(
                  child: Column(
                    mainAxisSize: MainAxisSize.min,
                    children: [
                      TextField(
                        controller: nameController,
                        onChanged: (_) => syncEmailFromName(),
                        decoration: const InputDecoration(
                          labelText: 'Nom Complet',
                          prefixIcon: Icon(Icons.person),
                        ),
                      ),
                      TextField(
                        controller: emailController,
                        decoration: const InputDecoration(
                          labelText: 'Email Professionnel',
                          prefixIcon: Icon(Icons.email),
                        ),
                      ),
                      TextField(
                        controller: passwordController,
                        obscureText: obscurePassword,
                        decoration: InputDecoration(
                          labelText: user == null
                              ? 'Mot de passe'
                              : 'Nouveau mot de passe (optionnel)',
                          prefixIcon: const Icon(Icons.lock),
                          helperText: user == null
                              ? 'Le mot de passe est créé uniquement quand tu cliques sur Générer.'
                              : 'Le mot de passe actuel ne peut pas être affiché. Laisse vide pour le conserver.',
                          suffixIcon: Row(
                            mainAxisSize: MainAxisSize.min,
                            children: [
                              IconButton(
                                tooltip: 'Générer un mot de passe à 6 chiffres',
                                icon: const Icon(Icons.casino),
                                onPressed: () {
                                  setModalState(() {
                                    applyGeneratedPassword();
                                  });
                                },
                              ),
                              IconButton(
                                tooltip: obscurePassword
                                    ? 'Afficher'
                                    : 'Masquer',
                                icon: Icon(
                                  obscurePassword
                                      ? Icons.visibility
                                      : Icons.visibility_off,
                                ),
                                onPressed: () => setModalState(
                                  () => obscurePassword = !obscurePassword,
                                ),
                              ),
                            ],
                          ),
                        ),
                      ),
                      const SizedBox(height: 12),
                      Align(
                        alignment: Alignment.centerLeft,
                        child: TextButton.icon(
                          onPressed: () {
                            setModalState(() {
                              emailController.text = _staffEmailFromName(
                                nameController.text,
                              );
                            });
                          },
                          icon: const Icon(Icons.auto_fix_high),
                          label: const Text('Remplir l’email depuis le nom'),
                        ),
                      ),
                      const SizedBox(height: 15),
                      const Text(
                        "Niveau d'accès :",
                        style: TextStyle(fontSize: 12, color: Colors.grey),
                      ),
                      DropdownButtonFormField<String>(
                        initialValue: role,
                        isExpanded: true,
                        items: _allowedRoles
                            .map(
                              (value) => DropdownMenuItem(
                                value: value,
                                child: Text(_roleLabel(value)),
                              ),
                            )
                            .toList(),
                        onChanged: (val) {
                          if (val != null) {
                            setModalState(() => role = val);
                          }
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
                  ElevatedButton(
                    onPressed: () async {
                      final data = {
                        'name': nameController.text,
                        'email': emailController.text,
                        'role': role,
                        'actor_role': widget.currentRole,
                      };
                      if (user != null) data['id'] = user['id'];
                      if (passwordController.text.isNotEmpty) {
                        data['password'] = passwordController.text;
                      }

                      final url = user == null
                          ? '$baseUrl/api/users'
                          : '$baseUrl/api/users/update';

                      try {
                        final resp = await http
                            .post(
                              Uri.parse(url),
                              headers: {'Content-Type': 'application/json'},
                              body: json.encode(data),
                            )
                            .timeout(const Duration(seconds: 5));
                        if (!context.mounted) return;
                        if (resp.statusCode == 200) {
                          Navigator.pop(context);
                          _fetchUsers();
                          ScaffoldMessenger.of(context).showSnackBar(
                            const SnackBar(
                              content: Text('Personnel mis à jour'),
                            ),
                          );
                        } else {
                          ScaffoldMessenger.of(context).showSnackBar(
                            SnackBar(content: Text('Erreur: ${resp.body}')),
                          );
                        }
                      } catch (e) {
                        if (!context.mounted) return;
                        ScaffoldMessenger.of(context).showSnackBar(
                          const SnackBar(
                            content: Text('Erreur de connexion serveur'),
                          ),
                        );
                      }
                    },
                    child: const Text('Enregistrer'),
                  ),
                ],
              );
            },
          );
        },
      );
    } finally {
      nameController.removeListener(syncEmailFromName);
      nameController.dispose();
      emailController.dispose();
      passwordController.dispose();
    }
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: AppBar(
        title: const Text('Gestion du Personnel'),
        actions: [
          IconButton(
            icon: const Icon(Icons.person_add),
            tooltip: 'Ajouter du personnel',
            onPressed: () => _showUserForm(),
          ),
        ],
      ),
      body: _isLoading
          ? const Center(child: CircularProgressIndicator())
          : Column(
              children: [
                Padding(
                  padding: const EdgeInsets.all(12.0),
                  child: ElevatedButton.icon(
                    onPressed: () => _showUserForm(),
                    icon: const Icon(Icons.add_circle_outline),
                    label: const Text('Ajouter un membre'),
                  ),
                ),
                Expanded(
                  child: ListView.builder(
                    itemCount: _users.length,
                    itemBuilder: (context, index) {
                      final user = _users[index];
                      return Card(
                        margin: const EdgeInsets.symmetric(
                          horizontal: 12,
                          vertical: 6,
                        ),
                        child: ListTile(
                          leading: CircleAvatar(
                            backgroundColor: user['role'] != 'receptionist'
                                ? const Color(0xFFFFF1F2)
                                : _primary.withValues(alpha: 0.10),
                            child: Icon(
                              user['role'] != 'receptionist'
                                  ? Icons.security
                                  : Icons.person,
                              color: user['role'] != 'receptionist'
                                  ? const Color(0xFFBE123C)
                                  : _primary,
                            ),
                          ),
                          title: Text(
                            user['name'],
                            style: const TextStyle(fontWeight: FontWeight.bold),
                          ),
                          subtitle: Text(
                            '${user['email']} (${_roleLabel(user['role']?.toString() ?? '')})',
                          ),
                          trailing: Row(
                            mainAxisSize: MainAxisSize.min,
                            children: [
                              IconButton(
                                icon: const Icon(Icons.edit, color: _primary),
                                onPressed: _canManageUser(user)
                                    ? () => _showUserForm(user: user)
                                    : null,
                              ),
                              IconButton(
                                icon: const Icon(
                                  Icons.delete,
                                  color: Colors.red,
                                ),
                                onPressed: _canManageUser(user)
                                    ? () {
                                        showDialog(
                                          context: context,
                                          builder: (context) => AlertDialog(
                                            title: const Text('Supprimer ?'),
                                            content: Text(
                                              "Voulez-vous vraiment retirer ${user['name']} du staff ?",
                                            ),
                                            actions: [
                                              TextButton(
                                                onPressed: () =>
                                                    Navigator.pop(context),
                                                child: const Text('Annuler'),
                                              ),
                                              TextButton(
                                                onPressed: () {
                                                  Navigator.pop(context);
                                                  _deleteUser(user['id']);
                                                },
                                                child: const Text(
                                                  'Supprimer',
                                                  style: TextStyle(
                                                    color: Colors.red,
                                                  ),
                                                ),
                                              ),
                                            ],
                                          ),
                                        );
                                      }
                                    : null,
                              ),
                            ],
                          ),
                        ),
                      );
                    },
                  ),
                ),
              ],
            ),
      floatingActionButton: FloatingActionButton.extended(
        onPressed: () => _showUserForm(),
        icon: const Icon(Icons.add),
        label: const Text('Ajouter'),
      ),
    );
  }
}
