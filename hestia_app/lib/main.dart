import 'dart:async';
import 'dart:convert';
import 'dart:ui';

import 'package:flutter/material.dart';
import 'package:http/http.dart' as http;
import 'package:url_launcher/url_launcher.dart';

import 'core/app_config.dart';
import 'core/formatters.dart';
import 'models/client_profile.dart';
import 'models/app_user.dart';
import 'screens/admin_users_page.dart';
import 'screens/reservations_list_page.dart';
import 'services/session_service.dart';
import 'widgets/availability_card.dart';
import 'widgets/client_autocomplete_field.dart';

const String baseUrl = AppConfig.apiBaseUrl;
const Color _primary = Color(0xFF0F766E);
const Color _primaryDark = Color(0xFF134E4A);
const Color _ink = Color(0xFF0F172A);
const Color _muted = Color(0xFF64748B);
const Color _surface = Color(0xFFFFFFFF);
const Color _pageBg = Color(0xFFF4F7F6);
const Color _border = Color(0xFFE2E8F0);
const Color _sand = Color(0xFFFFF7ED);
const Color _rose = Color(0xFFBE123C);

// Le mode démo donne un accès admin local et doit rester désactivé par défaut.
const bool _demoModeEnabled = bool.fromEnvironment(
  'ENABLE_DEMO_MODE',
  defaultValue: false,
);

void main() async {
  WidgetsFlutterBinding.ensureInitialized();
  final initialUser = await SessionService().loadUser();

  runApp(KamoroApp(initialUser: initialUser));
}

class KamoroApp extends StatelessWidget {
  final AppUser? initialUser;
  const KamoroApp({super.key, this.initialUser});

  @override
  Widget build(BuildContext context) {
    return MaterialApp(
      title: 'Kamoro Hotel Staff',
      theme: ThemeData(
        colorScheme: ColorScheme.fromSeed(
          seedColor: _primary,
          brightness: Brightness.light,
        ),
        scaffoldBackgroundColor: _pageBg,
        useMaterial3: true,
        appBarTheme: const AppBarTheme(
          backgroundColor: _surface,
          foregroundColor: _ink,
          centerTitle: false,
          elevation: 0,
          surfaceTintColor: Colors.transparent,
          titleTextStyle: TextStyle(
            color: _ink,
            fontSize: 18,
            fontWeight: FontWeight.w900,
          ),
        ),
        cardTheme: CardThemeData(
          color: _surface,
          elevation: 0,
          surfaceTintColor: Colors.transparent,
          shape: RoundedRectangleBorder(
            borderRadius: BorderRadius.circular(16),
            side: const BorderSide(color: _border),
          ),
        ),
        inputDecorationTheme: InputDecorationTheme(
          filled: true,
          fillColor: Colors.white,
          border: OutlineInputBorder(borderRadius: BorderRadius.circular(8)),
          enabledBorder: OutlineInputBorder(
            borderRadius: BorderRadius.circular(8),
            borderSide: const BorderSide(color: _border),
          ),
          focusedBorder: OutlineInputBorder(
            borderRadius: BorderRadius.circular(8),
            borderSide: const BorderSide(color: _primary, width: 1.5),
          ),
        ),
        elevatedButtonTheme: ElevatedButtonThemeData(
          style: ElevatedButton.styleFrom(
            backgroundColor: _primary,
            foregroundColor: Colors.white,
            elevation: 0,
            minimumSize: const Size(0, 46),
            shape: RoundedRectangleBorder(
              borderRadius: BorderRadius.circular(8),
            ),
            textStyle: const TextStyle(fontWeight: FontWeight.w800),
          ),
        ),
        floatingActionButtonTheme: const FloatingActionButtonThemeData(
          backgroundColor: _primary,
          foregroundColor: Colors.white,
          elevation: 2,
          shape: RoundedRectangleBorder(
            borderRadius: BorderRadius.all(Radius.circular(18)),
          ),
        ),
      ),
      home: initialUser != null
          ? StaffDashboard(role: initialUser!.role, userName: initialUser!.name)
          : const LoginPage(),
      debugShowCheckedModeBanner: false,
    );
  }
}

Route<T> _softRoute<T>(Widget page) {
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

// Removed mockUsers as we use real database now

class LoginPage extends StatefulWidget {
  const LoginPage({super.key});
  @override
  State<LoginPage> createState() => _LoginPageState();
}

class _LoginPageState extends State<LoginPage> {
  final _emailController = TextEditingController();
  final _passwordController = TextEditingController();
  String _errorMessage = '';
  bool _isLoading = false;

  Future<void> _handleLogin() async {
    setState(() {
      _errorMessage = '';
      _isLoading = true;
    });

    try {
      final response = await http.post(
        Uri.parse('$baseUrl/api/login'),
        headers: {'Content-Type': 'application/json'},
        body: json.encode({
          'email': _emailController.text.trim(),
          'password': _passwordController.text.trim(),
        }),
      );

      if (response.statusCode == 200) {
        final data = json.decode(response.body);
        final user = AppUser.fromJson(data['user']);
        await SessionService().saveUser(user);

        if (!mounted) return;
        Navigator.pushReplacement(
          context,
          _softRoute(StaffDashboard(role: user.role, userName: user.name)),
        );
      } else {
        setState(() {
          _errorMessage = 'Identifiants incorrects.';
        });
      }
    } catch (e) {
      setState(() {
        _errorMessage = _demoModeEnabled
            ? 'Erreur serveur. Voulez-vous utiliser le mode démo ?'
            : 'Erreur serveur. Veuillez réessayer plus tard.';
      });
    } finally {
      setState(() => _isLoading = false);
    }
  }

  void _enterDemoMode() {
    if (!_demoModeEnabled) return;

    Navigator.pushReplacement(
      context,
      _softRoute(const StaffDashboard(role: 'admin', userName: 'Admin (Démo)')),
    );
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      body: Center(
        child: SingleChildScrollView(
          padding: const EdgeInsets.all(20),
          child: ConstrainedBox(
            constraints: const BoxConstraints(maxWidth: 430),
            child: Card(
              child: Padding(
                padding: const EdgeInsets.all(28),
                child: Column(
                  mainAxisSize: MainAxisSize.min,
                  crossAxisAlignment: CrossAxisAlignment.stretch,
                  children: [
                    Container(
                      width: 52,
                      height: 52,
                      decoration: BoxDecoration(
                        color: _primary.withValues(alpha: 0.10),
                        borderRadius: BorderRadius.circular(12),
                      ),
                      child: const Icon(Icons.hotel, color: _primary, size: 30),
                    ),
                    const SizedBox(height: 18),
                    const Text(
                      'Kamoro Hotel',
                      style: TextStyle(
                        fontSize: 28,
                        fontWeight: FontWeight.w900,
                        color: _ink,
                      ),
                    ),
                    const SizedBox(height: 4),
                    const Text(
                      'Espace réception et gestion',
                      style: TextStyle(
                        color: _muted,
                        fontWeight: FontWeight.w600,
                      ),
                    ),
                    const SizedBox(height: 24),
                    TextField(
                      controller: _emailController,
                      decoration: const InputDecoration(
                        labelText: 'Email professionnel',
                        prefixIcon: Icon(Icons.mail_outline),
                      ),
                    ),
                    const SizedBox(height: 12),
                    TextField(
                      controller: _passwordController,
                      obscureText: true,
                      decoration: const InputDecoration(
                        labelText: 'Mot de passe',
                        prefixIcon: Icon(Icons.lock_outline),
                      ),
                      onSubmitted: (_) => _isLoading ? null : _handleLogin(),
                    ),
                    if (_errorMessage.isNotEmpty)
                      Padding(
                        padding: const EdgeInsets.only(top: 12),
                        child: Column(
                          children: [
                            Container(
                              padding: const EdgeInsets.all(12),
                              width: double.infinity,
                              decoration: BoxDecoration(
                                color: Colors.red.shade50,
                                borderRadius: BorderRadius.circular(8),
                                border: Border.all(color: Colors.red.shade100),
                              ),
                              child: Text(
                                _errorMessage,
                                style: TextStyle(
                                  color: Colors.red.shade800,
                                  fontWeight: FontWeight.w700,
                                ),
                              ),
                            ),
                            if (_demoModeEnabled &&
                                _errorMessage.contains('Erreur serveur'))
                              Padding(
                                padding: const EdgeInsets.only(top: 8),
                                child: TextButton(
                                  onPressed: _enterDemoMode,
                                  child: const Text('Entrer en mode Démo'),
                                ),
                              ),
                          ],
                        ),
                      ),
                    const SizedBox(height: 22),
                    _isLoading
                        ? const Center(child: CircularProgressIndicator())
                        : ElevatedButton.icon(
                            onPressed: _handleLogin,
                            icon: const Icon(Icons.login),
                            label: const Text('Se connecter'),
                          ),
                  ],
                ),
              ),
            ),
          ),
        ),
      ),
    );
  }
}

class StaffDashboard extends StatefulWidget {
  final String role;
  final String userName;
  const StaffDashboard({super.key, required this.role, required this.userName});
  @override
  State<StaffDashboard> createState() => _StaffDashboardState();
}

class _StaffDashboardState extends State<StaffDashboard> {
  List<dynamic> _categories = [];
  Map<String, dynamic> _aiPredictions = {};
  bool _isLoading = true;
  String _errorMessage = '';
  Timer? _timer;
  DateTime _selectedDate = DateTime.now();
  int _pendingGuestsCount = 0;
  int _arrivedGuestsCount = 0;

  @override
  void initState() {
    super.initState();
    _fetchLiveAvailability();
    _timer = Timer.periodic(
      const Duration(seconds: 10),
      (_) => _fetchLiveAvailability(isSilent: true),
    );
  }

  Future<void> _launchURL(String url) async {
    final Uri uri = Uri.parse(url);
    if (!await launchUrl(uri, mode: LaunchMode.externalApplication)) {
      debugPrint('Could not launch $url');
    }
  }

  @override
  void dispose() {
    _timer?.cancel();
    super.dispose();
  }

  Future<void> _fetchLiveAvailability({bool isSilent = false}) async {
    if (!isSilent) setState(() => _isLoading = true);
    setState(() => _errorMessage = '');
    String dateStr = _selectedDate.toIso8601String().substring(0, 10);
    final cacheBust = DateTime.now().millisecondsSinceEpoch;

    try {
      final availResp = await http
          .get(
            Uri.parse(
              '$baseUrl/api/live-availability?date=$dateStr&_ts=$cacheBust',
            ),
          )
          .timeout(const Duration(seconds: 5));
      if (availResp.statusCode == 200) {
        setState(() {
          _categories = json.decode(availResp.body);
          if (!isSilent) _isLoading = false;
        });
      } else {
        _useFallbackData('Erreur serveur: ${availResp.statusCode}');
        if (!isSilent) setState(() => _isLoading = false);
      }
    } catch (e) {
      debugPrint("$e");
      _useFallbackData('Mode Hors-ligne : Serveur injoignable.');
      if (!isSilent) setState(() => _isLoading = false);
    }

    http
        .get(
          Uri.parse(
            '$baseUrl/api/reservations/all?date=$dateStr&_ts=$cacheBust',
          ),
        )
        .then((reservationsResp) {
          if (!mounted || reservationsResp.statusCode != 200) return;
          final reservations =
              json.decode(reservationsResp.body) as List<dynamic>;
          final pendingCount = reservations.where((reservation) {
            return (reservation['status']?.toString() ?? '') == 'en_attente';
          }).length;
          final arrivedCount = reservations.where((reservation) {
            return (reservation['status']?.toString() ?? '') == 'arrive';
          }).length;
          setState(() {
            _pendingGuestsCount = pendingCount;
            _arrivedGuestsCount = arrivedCount;
          });
        })
        .catchError((e) {
          debugPrint("Pending guests fetch error: $e");
        });

    http
        .get(
          Uri.parse(
            '$baseUrl/api/dashboard/predictions?days=30&_ts=$cacheBust',
          ),
        )
        .then((aiResp) {
          if (!mounted || aiResp.statusCode != 200) return;
          final aiData = json.decode(aiResp.body);
          if (aiData['status'] == 'success') {
            setState(() {
              _aiPredictions = aiData['results'] ?? {};
            });
          }
        })
        .catchError((e) {
          debugPrint("Predictions fetch error: $e");
        });
  }

  void _useFallbackData(String message) {
    setState(() {
      _errorMessage = message;
      // Si on n'a aucune donnée, on charge des données de secours pour que l'app "fonctionne" visuellement
      if (_categories.isEmpty) {
        _categories = [
          {
            "type": "Chambre Double",
            "model": "Standard - Vue Jardin",
            "available": 5,
            "total": 12,
            "base_price": 85000,
          },
          {
            "type": "Chambre Double",
            "model": "Supérieure - Vue Mer",
            "available": 2,
            "total": 8,
            "base_price": 125000,
          },
          {
            "type": "Suite",
            "model": "Famille - Terrasse",
            "available": 0,
            "total": 3,
            "base_price": 220000,
          },
        ];
      }
    });
  }

  @override
  Widget build(BuildContext context) {
    final isDesktop = MediaQuery.sizeOf(context).width >= 900;
    final dashboardContent = _ReceptionDashboardContent(
      role: widget.role,
      userName: widget.userName,
      selectedDate: _selectedDate,
      isLoading: _isLoading,
      errorMessage: _errorMessage,
      categories: _categories,
      aiPredictions: _aiPredictions,
      pendingGuestsCount: _pendingGuestsCount,
      arrivedGuestsCount: _arrivedGuestsCount,
      onReservationsTap: () async {
        await Navigator.push(
          context,
          _softRoute(
            ReservationsListPage(
              role: widget.role,
              userName: widget.userName,
              initialDate: _selectedDate,
            ),
          ),
        );
        if (mounted) {
          _fetchLiveAvailability();
        }
      },
      onDateTap: () async {
        var d = await showDatePicker(
          context: context,
          initialDate: _selectedDate,
          firstDate: DateTime.now().subtract(const Duration(days: 1)),
          lastDate: DateTime.now().add(const Duration(days: 365)),
        );
        if (d != null) {
          setState(() => _selectedDate = d);
          _fetchLiveAvailability();
        }
      },
      onRetry: () => _fetchLiveAvailability(),
    );

    return Scaffold(
      appBar: isDesktop
          ? null
          : AppBar(
              title: const Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  Text('Kamoro Hotel'),
                  Text(
                    'Réception',
                    style: TextStyle(
                      color: _muted,
                      fontSize: 12,
                      fontWeight: FontWeight.w700,
                    ),
                  ),
                ],
              ),
            ),
      drawer: isDesktop
          ? null
          : _MobileReceptionDrawer(
              role: widget.role,
              userName: widget.userName,
              onDashboardTap: () {
                Navigator.pop(context);
                _launchURL('http://localhost:8000/dashboard');
              },
              onManageStaff: () {
                Navigator.pop(context);
                Navigator.push(context, _softRoute(const AdminUsersPage()));
              },
              onLogout: () async {
                await SessionService().clear();
                if (!context.mounted) return;
                Navigator.pushAndRemoveUntil(
                  context,
                  _softRoute(const LoginPage()),
                  (route) => false,
                );
              },
            ),
      body: Container(
        decoration: const BoxDecoration(
          gradient: LinearGradient(
            begin: Alignment.topLeft,
            end: Alignment.bottomRight,
            colors: [Color(0xFFF8FAFC), _sand, Color(0xFFEFF6F5)],
          ),
        ),
        child: SafeArea(
          child: Row(
            children: [
              if (isDesktop)
                _FrostedSideNav(
                  role: widget.role,
                  userName: widget.userName,
                  onManageStaff: () => Navigator.push(
                    context,
                    _softRoute(const AdminUsersPage()),
                  ),
                  onDashboardTap: () =>
                      _launchURL('http://localhost:8000/dashboard'),
                  onLogout: () async {
                    await SessionService().clear();
                    if (!context.mounted) return;
                    Navigator.pushAndRemoveUntil(
                      context,
                      _softRoute(const LoginPage()),
                      (route) => false,
                    );
                  },
                ),
              Expanded(child: dashboardContent),
            ],
          ),
        ),
      ),
      floatingActionButton: FloatingActionButton.extended(
        onPressed: () => Navigator.push(
          context,
          _softRoute(NewBookingPage(userName: widget.userName)),
        ).then((_) => _fetchLiveAvailability()),
        label: const Text('Nouvelle réservation'),
        icon: const Icon(Icons.add),
      ),
    );
  }
}

class _MobileReceptionDrawer extends StatelessWidget {
  const _MobileReceptionDrawer({
    required this.role,
    required this.userName,
    required this.onDashboardTap,
    required this.onManageStaff,
    required this.onLogout,
  });

  final String role;
  final String userName;
  final VoidCallback onDashboardTap;
  final VoidCallback onManageStaff;
  final Future<void> Function() onLogout;

  @override
  Widget build(BuildContext context) {
    return Drawer(
      backgroundColor: Colors.white,
      child: ListView(
        padding: EdgeInsets.zero,
        children: [
          UserAccountsDrawerHeader(
            decoration: const BoxDecoration(color: _primaryDark),
            accountName: Text(
              userName,
              style: const TextStyle(fontWeight: FontWeight.bold, fontSize: 18),
            ),
            accountEmail: Text(
              role == 'admin' ? 'Administrateur' : 'Réceptionniste',
              style: const TextStyle(fontStyle: FontStyle.italic),
            ),
            currentAccountPicture: CircleAvatar(
              backgroundColor: Colors.white,
              child: Text(
                userName.isNotEmpty ? userName[0].toUpperCase() : 'U',
                style: const TextStyle(fontSize: 40.0, color: _primary),
              ),
            ),
          ),
          if (role == 'admin') ...[
            ListTile(
              leading: const Icon(Icons.manage_accounts),
              title: const Text('Gérer le Staff'),
              onTap: onManageStaff,
            ),
            ListTile(
              leading: const Icon(Icons.analytics_outlined),
              title: const Text('Manager'),
              onTap: onDashboardTap,
            ),
          ],
          ListTile(
            leading: const Icon(Icons.logout, color: Colors.red),
            title: const Text(
              'Se déconnecter',
              style: TextStyle(color: Colors.red),
            ),
            onTap: onLogout,
          ),
        ],
      ),
    );
  }
}

class _FrostedSideNav extends StatelessWidget {
  const _FrostedSideNav({
    required this.role,
    required this.userName,
    required this.onDashboardTap,
    required this.onManageStaff,
    required this.onLogout,
  });

  final String role;
  final String userName;
  final VoidCallback onDashboardTap;
  final VoidCallback onManageStaff;
  final Future<void> Function() onLogout;

  @override
  Widget build(BuildContext context) {
    return Padding(
      padding: const EdgeInsets.fromLTRB(18, 18, 0, 18),
      child: ClipRRect(
        borderRadius: BorderRadius.circular(28),
        child: BackdropFilter(
          filter: ImageFilter.blur(sigmaX: 18, sigmaY: 18),
          child: Container(
            width: 248,
            padding: const EdgeInsets.all(18),
            decoration: BoxDecoration(
              color: Colors.white.withValues(alpha: 0.54),
              borderRadius: BorderRadius.circular(28),
              border: Border.all(color: Colors.white.withValues(alpha: 0.76)),
              boxShadow: [
                BoxShadow(
                  color: _ink.withValues(alpha: 0.08),
                  blurRadius: 30,
                  offset: const Offset(0, 18),
                ),
              ],
            ),
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Row(
                  children: [
                    Container(
                      width: 46,
                      height: 46,
                      decoration: BoxDecoration(
                        color: _primary.withValues(alpha: 0.12),
                        borderRadius: BorderRadius.circular(16),
                      ),
                      child: const Icon(Icons.hotel, color: _primary),
                    ),
                    const SizedBox(width: 12),
                    const Expanded(
                      child: Column(
                        crossAxisAlignment: CrossAxisAlignment.start,
                        children: [
                          Text(
                            'Kamoro',
                            style: TextStyle(
                              color: _ink,
                              fontSize: 19,
                              fontWeight: FontWeight.w900,
                            ),
                          ),
                          Text(
                            'Réception',
                            style: TextStyle(
                              color: _muted,
                              fontSize: 12,
                              fontWeight: FontWeight.w700,
                            ),
                          ),
                        ],
                      ),
                    ),
                  ],
                ),
                const SizedBox(height: 28),
                _SideNavButton(
                  icon: Icons.bedroom_parent_outlined,
                  label: 'Chambres',
                  selected: true,
                  onTap: () {},
                ),
                if (role == 'admin') ...[
                  const SizedBox(height: 10),
                  _SideNavButton(
                    icon: Icons.manage_accounts_outlined,
                    label: 'Staff',
                    onTap: onManageStaff,
                  ),
                  const SizedBox(height: 10),
                  _SideNavButton(
                    icon: Icons.analytics_outlined,
                    label: 'Manager',
                    onTap: onDashboardTap,
                  ),
                ],
                const Spacer(),
                Container(
                  padding: const EdgeInsets.all(14),
                  decoration: BoxDecoration(
                    color: Colors.white.withValues(alpha: 0.52),
                    borderRadius: BorderRadius.circular(22),
                    border: Border.all(
                      color: Colors.white.withValues(alpha: 0.70),
                    ),
                  ),
                  child: Row(
                    children: [
                      CircleAvatar(
                        backgroundColor: _primaryDark,
                        child: Text(
                          userName.isNotEmpty ? userName[0].toUpperCase() : 'U',
                          style: const TextStyle(
                            color: Colors.white,
                            fontWeight: FontWeight.w900,
                          ),
                        ),
                      ),
                      const SizedBox(width: 10),
                      Expanded(
                        child: Column(
                          crossAxisAlignment: CrossAxisAlignment.start,
                          children: [
                            Text(
                              userName,
                              maxLines: 1,
                              overflow: TextOverflow.ellipsis,
                              style: const TextStyle(
                                color: _ink,
                                fontWeight: FontWeight.w900,
                              ),
                            ),
                            Text(
                              role == 'admin'
                                  ? 'Administrateur'
                                  : 'Réceptionniste',
                              style: const TextStyle(
                                color: _muted,
                                fontSize: 12,
                                fontWeight: FontWeight.w700,
                              ),
                            ),
                          ],
                        ),
                      ),
                    ],
                  ),
                ),
                const SizedBox(height: 12),
                _SideNavButton(
                  icon: Icons.logout,
                  label: 'Déconnexion',
                  destructive: true,
                  onTap: onLogout,
                ),
              ],
            ),
          ),
        ),
      ),
    );
  }
}

class _SideNavButton extends StatelessWidget {
  const _SideNavButton({
    required this.icon,
    required this.label,
    required this.onTap,
    this.selected = false,
    this.destructive = false,
  });

  final IconData icon;
  final String label;
  final VoidCallback onTap;
  final bool selected;
  final bool destructive;

  @override
  Widget build(BuildContext context) {
    final color = destructive ? _rose : (selected ? _primaryDark : _ink);
    return SizedBox(
      width: double.infinity,
      child: TextButton.icon(
        onPressed: onTap,
        icon: Icon(icon, size: 20),
        label: Align(alignment: Alignment.centerLeft, child: Text(label)),
        style: TextButton.styleFrom(
          foregroundColor: color,
          backgroundColor: selected
              ? Colors.white.withValues(alpha: 0.70)
              : Colors.transparent,
          minimumSize: const Size(0, 48),
          padding: const EdgeInsets.symmetric(horizontal: 14),
          shape: RoundedRectangleBorder(
            borderRadius: BorderRadius.circular(18),
          ),
          textStyle: const TextStyle(fontWeight: FontWeight.w900),
        ),
      ),
    );
  }
}

class _ReceptionDashboardContent extends StatelessWidget {
  const _ReceptionDashboardContent({
    required this.role,
    required this.userName,
    required this.selectedDate,
    required this.isLoading,
    required this.errorMessage,
    required this.categories,
    required this.aiPredictions,
    required this.pendingGuestsCount,
    required this.arrivedGuestsCount,
    required this.onReservationsTap,
    required this.onDateTap,
    required this.onRetry,
  });

  final String role;
  final String userName;
  final DateTime selectedDate;
  final bool isLoading;
  final String errorMessage;
  final List<dynamic> categories;
  final Map<String, dynamic> aiPredictions;
  final int pendingGuestsCount;
  final int arrivedGuestsCount;
  final VoidCallback onReservationsTap;
  final VoidCallback onDateTap;
  final VoidCallback onRetry;

  @override
  Widget build(BuildContext context) {
    return Padding(
      padding: const EdgeInsets.fromLTRB(26, 22, 26, 0),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Wrap(
            spacing: 12,
            runSpacing: 12,
            crossAxisAlignment: WrapCrossAlignment.center,
            children: [
              SizedBox(
                width: 420,
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Text(
                      'Chambres',
                      style: Theme.of(context).textTheme.headlineMedium
                          ?.copyWith(
                            color: _ink,
                            fontWeight: FontWeight.w900,
                            letterSpacing: 0,
                          ),
                    ),
                    const SizedBox(height: 2),
                    Text(
                      'Vue réception de $userName',
                      style: const TextStyle(
                        color: _muted,
                        fontWeight: FontWeight.w700,
                      ),
                    ),
                  ],
                ),
              ),
              _SoftActionButton(
                icon: Icons.list_alt,
                label: 'Réservations',
                onTap: onReservationsTap,
              ),
              _SoftActionButton(
                icon: Icons.calendar_today_outlined,
                label: selectedDate.toIso8601String().substring(0, 10),
                onTap: onDateTap,
              ),
              _KpiChip(
                icon: Icons.hourglass_bottom,
                label: 'En attente',
                value: pendingGuestsCount.toString(),
              ),
              _KpiChip(
                icon: Icons.how_to_reg_outlined,
                label: 'Check-in',
                value: arrivedGuestsCount.toString(),
              ),
            ],
          ),
          const SizedBox(height: 22),
          Expanded(
            child: AnimatedSwitcher(
              duration: const Duration(milliseconds: 320),
              switchInCurve: Curves.easeOutCubic,
              switchOutCurve: Curves.easeInCubic,
              child: _buildBody(context),
            ),
          ),
        ],
      ),
    );
  }

  int? _getAiSuggestedPrice(dynamic category) {
    String roomType = category['type'].toString().trim();
    String roomModel = category['model'].toString().trim();
    String key = "$roomType - $roomModel";
    String dateStr = selectedDate.toIso8601String().substring(0, 10);

    if (aiPredictions.containsKey(key)) {
      List<dynamic> predictions = aiPredictions[key];
      var prediction = predictions.firstWhere(
        (p) => p['date'] == dateStr,
        orElse: () => null,
      );
      if (prediction != null) {
        return prediction['adjusted_price_ariary'] ??
            prediction['suggested_price_ariary'];
      }
    }
    return null;
  }

  Widget _buildBody(BuildContext context) {
    if (isLoading) {
      return const Center(
        key: ValueKey('loading'),
        child: CircularProgressIndicator(),
      );
    }
    if (errorMessage.isNotEmpty && categories.isEmpty) {
      return Center(
        key: const ValueKey('error'),
        child: Padding(
          padding: const EdgeInsets.all(20.0),
          child: Column(
            mainAxisAlignment: MainAxisAlignment.center,
            children: [
              const Icon(Icons.error_outline, size: 48, color: _rose),
              const SizedBox(height: 16),
              Text(
                errorMessage,
                textAlign: TextAlign.center,
                style: const TextStyle(
                  color: _rose,
                  fontWeight: FontWeight.w800,
                ),
              ),
              const SizedBox(height: 16),
              ElevatedButton.icon(
                onPressed: onRetry,
                icon: const Icon(Icons.refresh),
                label: const Text('Réessayer'),
              ),
            ],
          ),
        ),
      );
    }
    return GridView.builder(
      key: const ValueKey('grid'),
      padding: const EdgeInsets.fromLTRB(0, 0, 0, 96),
      gridDelegate: const SliverGridDelegateWithMaxCrossAxisExtent(
        maxCrossAxisExtent: 310,
        childAspectRatio: 1.18,
        mainAxisSpacing: 16,
        crossAxisSpacing: 16,
      ),
      itemCount: categories.length,
      itemBuilder: (context, index) {
        final cat = categories[index];
        final suggestedPrice = _getAiSuggestedPrice(cat);

        return TweenAnimationBuilder<double>(
          tween: Tween(begin: 0, end: 1),
          duration: Duration(milliseconds: 260 + (index.clamp(0, 8) * 35)),
          curve: Curves.easeOutCubic,
          builder: (context, value, child) {
            return Opacity(
              opacity: value,
              child: Transform.translate(
                offset: Offset(0, 10 * (1 - value)),
                child: child,
              ),
            );
          },
          child: AvailabilityCard(
            category: Map<String, dynamic>.from(cat),
            suggestedPrice: suggestedPrice,
          ),
        );
      },
    );
  }
}

class _KpiChip extends StatelessWidget {
  const _KpiChip({
    required this.icon,
    required this.label,
    required this.value,
  });

  final IconData icon;
  final String label;
  final String value;

  @override
  Widget build(BuildContext context) {
    return Container(
      padding: const EdgeInsets.symmetric(horizontal: 14, vertical: 12),
      decoration: BoxDecoration(
        color: Colors.white.withValues(alpha: 0.72),
        borderRadius: BorderRadius.circular(18),
        border: Border.all(color: Colors.white.withValues(alpha: 0.88)),
      ),
      child: Row(
        mainAxisSize: MainAxisSize.min,
        children: [
          Icon(icon, size: 18, color: _primaryDark),
          const SizedBox(width: 8),
          Column(
            crossAxisAlignment: CrossAxisAlignment.start,
            mainAxisSize: MainAxisSize.min,
            children: [
              Text(
                label,
                style: const TextStyle(
                  fontSize: 11,
                  fontWeight: FontWeight.w800,
                  color: _muted,
                ),
              ),
              Text(
                value,
                style: const TextStyle(
                  fontSize: 16,
                  fontWeight: FontWeight.w900,
                  color: _ink,
                ),
              ),
            ],
          ),
        ],
      ),
    );
  }
}

class _SoftActionButton extends StatelessWidget {
  const _SoftActionButton({
    required this.icon,
    required this.label,
    required this.onTap,
  });

  final IconData icon;
  final String label;
  final VoidCallback onTap;

  @override
  Widget build(BuildContext context) {
    return OutlinedButton.icon(
      onPressed: onTap,
      icon: Icon(icon, size: 19),
      label: Text(label),
      style: OutlinedButton.styleFrom(
        foregroundColor: _ink,
        backgroundColor: Colors.white.withValues(alpha: 0.72),
        side: BorderSide(color: Colors.white.withValues(alpha: 0.88)),
        minimumSize: const Size(0, 50),
        padding: const EdgeInsets.symmetric(horizontal: 18),
        shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(18)),
        textStyle: const TextStyle(fontWeight: FontWeight.w900),
      ),
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

class NewBookingPage extends StatefulWidget {
  final String userName;
  const NewBookingPage({super.key, required this.userName});
  @override
  State<NewBookingPage> createState() => _NewBookingPageState();
}

class _NewBookingPageState extends State<NewBookingPage> {
  final _nameController = TextEditingController();
  final _phoneController = TextEditingController();
  final _emailController = TextEditingController();
  DateTime _checkIn = DateTime.now();
  DateTime _checkOut = DateTime.now().add(const Duration(days: 1));
  List<dynamic> _allAvailableRooms = [];
  Map<String, dynamic> _aiPredictions = {};
  final List<dynamic> _selectedRooms = [];
  int _extraBeds = 0;
  int _extraMattresses = 0;
  bool _loadingRooms = false;
  bool _isBookingCom = false;
  ClientProfile? _selectedClient;

  @override
  void initState() {
    super.initState();
    _fetchData();
  }

  @override
  void dispose() {
    _nameController.dispose();
    _phoneController.dispose();
    _emailController.dispose();
    super.dispose();
  }

  String _clientName() {
    return _nameController.text.trim();
  }

  void _applyClient(ClientProfile client) {
    setState(() {
      _selectedClient = client;
      _nameController.text = client.displayName;
      _phoneController.text = client.phoneNumber?.trim() ?? '';
    });
  }

  Future<void> _fetchData() async {
    setState(() {
      _loadingRooms = true;
      _selectedRooms.clear();
    });

    try {
      final roomsResp = await http.get(
        Uri.parse(
          '$baseUrl/api/available-rooms?check_in=${_checkIn.toIso8601String().substring(0, 10)}&check_out=${_checkOut.toIso8601String().substring(0, 10)}',
        ),
      );
      if (roomsResp.statusCode == 200) {
        _allAvailableRooms = json.decode(roomsResp.body);
      }
    } catch (e) {
      debugPrint("Rooms fetch error: $e");
    }

    try {
      final aiResp = await http
          .get(Uri.parse('$baseUrl/api/dashboard/predictions?days=30'))
          .timeout(const Duration(seconds: 3));

      if (aiResp.statusCode == 200) {
        var data = json.decode(aiResp.body);
        // On accepte les résultats même si c'est un fallback (prix planchers)
        if (data['status'] == 'success') {
          _aiPredictions = data['results'] ?? {};
        }
      }
    } catch (e) {
      debugPrint("AI fetch error: $e");
      _aiPredictions = {};
    }

    setState(() => _loadingRooms = false);
  }

  Future<void> _saveBooking() async {
    if (_clientName().isEmpty || _selectedRooms.isEmpty) {
      ScaffoldMessenger.of(context).showSnackBar(
        const SnackBar(
          content: Text(
            'Veuillez remplir le nom et choisir au moins une chambre.',
          ),
        ),
      );
      return;
    }

    if (_phoneController.text.trim().isEmpty &&
        _emailController.text.trim().isEmpty) {
      ScaffoldMessenger.of(context).showSnackBar(
        const SnackBar(
          content: Text(
            'Veuillez renseigner au moins un numéro de téléphone ou un email.',
          ),
          backgroundColor: Colors.orange,
        ),
      );
      return;
    }

    setState(() => _loadingRooms = true);
    try {
      final response = await http.post(
        Uri.parse('$baseUrl/api/bookings'),
        headers: {'Content-Type': 'application/json'},
        body: json.encode({
          'client_name': _clientName(),
          'customer_phone': _phoneController.text.trim(),
          'customer_email': _emailController.text.trim(),
          'phone_number': _phoneController.text.trim(),
          'check_in': _checkIn.toIso8601String().substring(0, 10),
          'check_out': _checkOut.toIso8601String().substring(0, 10),
          'room_ids': _selectedRooms.map((r) => r['id']).toList(),
          'room_prices': _selectedRooms
              .map((r) => {'id': r['id'], 'price': _getSuggestedPrice(r)})
              .toList(),
          'extra_beds': _extraBeds,
          'extra_mattresses': _extraMattresses,
          'source': _isBookingCom
              ? 'Booking'
              : (_phoneController.text.trim().isNotEmpty ? 'Appel' : 'Mail'),
          'receptionist_name': widget.userName,
        }),
      );

      if (response.statusCode == 201 || response.statusCode == 200) {
        if (mounted) {
          ScaffoldMessenger.of(context).showSnackBar(
            const SnackBar(
              content: Text('Réservation enregistrée avec succès !'),
              backgroundColor: Colors.green,
            ),
          );
          Navigator.pop(context);
        }
      } else {
        if (mounted) {
          ScaffoldMessenger.of(context).showSnackBar(
            SnackBar(
              content: Text('Erreur: ${response.body}'),
              backgroundColor: Colors.red,
            ),
          );
        }
      }
    } catch (e) {
      if (mounted) {
        ScaffoldMessenger.of(context).showSnackBar(
          const SnackBar(
            content: Text('Impossible de contacter le serveur.'),
            backgroundColor: Colors.red,
          ),
        );
      }
    }
    setState(() => _loadingRooms = false);
  }

  int _getSuggestedPrice(dynamic room) {
    final fixedPrice = _getFixedPrice(room);
    if (room['is_fixed_price'] == true) return fixedPrice;
    if (_isBookingCom) return 162500; // 32.5€ * 5000

    // Nettoyage de la clé pour éviter les problèmes d'espaces ou d'accents
    String roomType = room['type'].toString().trim();
    String roomModel = room['model'].toString().trim();
    String key = "$roomType - $roomModel";
    String dateStr = _checkIn.toIso8601String().substring(0, 10);

    if (_aiPredictions.containsKey(key)) {
      List<dynamic> predictions = _aiPredictions[key];
      var prediction = predictions.firstWhere(
        (p) => p['date'] == dateStr,
        orElse: () => null,
      );
      if (prediction != null) {
        return fixedPrice;
      }
    }

    // Fallback : Recherche floue si la clé exacte ne matche pas
    for (var aiKey in _aiPredictions.keys) {
      if (aiKey.contains(roomType) && aiKey.contains(roomModel)) {
        List<dynamic> predictions = _aiPredictions[aiKey];
        var prediction = predictions.firstWhere(
          (p) => p['date'] == dateStr,
          orElse: () => null,
        );
        if (prediction != null) {
          return fixedPrice;
        }
      }
    }
    return fixedPrice;
  }

  int _getFixedPrice(dynamic room) {
    var basePrice = room['base_price_ariary'];
    if (basePrice is String) {
      return int.tryParse(basePrice) ?? 0;
    }
    return basePrice as int? ?? 0;
  }

  int _calculateTotalPrice() {
    int total = 0;
    int nights = _checkOut.difference(_checkIn).inDays;
    if (nights < 1) nights = 1;
    for (var room in _selectedRooms) {
      total += _getSuggestedPrice(room) * nights;
    }
    total += (_extraBeds * 50000) + (_extraMattresses * 30000);
    return total;
  }

  @override
  Widget build(BuildContext context) {
    List<dynamic> filteredRooms = _allAvailableRooms;
    if (_isBookingCom) {
      filteredRooms = _allAvailableRooms
          .where(
            (r) =>
                r['type'] == 'Chambre Double' &&
                r['model'].toString().contains('Supérieure'),
          )
          .toList();
    }

    return Scaffold(
      appBar: AppBar(title: const Text('Nouvelle réservation')),
      body: Row(
        children: [
          Expanded(
            flex: 1,
            child: SingleChildScrollView(
              padding: const EdgeInsets.all(16.0),
              child: Column(
                children: [
                  ClientAutocompleteField(
                    controller: _nameController,
                    labelText: 'Nom du client',
                    prefixIcon: Icons.person_outline,
                    keyboardType: TextInputType.name,
                    textInputAction: TextInputAction.next,
                    valueBuilder: (client) => client.displayName,
                    onSelected: _applyClient,
                  ),
                  const SizedBox(height: 12),
                  ClientAutocompleteField(
                    controller: _phoneController,
                    labelText: 'Téléphone',
                    prefixIcon: Icons.phone,
                    keyboardType: TextInputType.phone,
                    textInputAction: TextInputAction.next,
                    valueBuilder: (client) => client.phoneNumber?.trim() ?? '',
                    onSelected: _applyClient,
                  ),
                  const SizedBox(height: 12),
                  TextField(
                    controller: _emailController,
                    keyboardType: TextInputType.text, // Format libre
                    decoration: const InputDecoration(
                      labelText: 'Email / Autre contact (Requis si pas de tel)',
                      prefixIcon: Icon(Icons.contact_mail),
                      border: OutlineInputBorder(),
                    ),
                  ),
                  const SizedBox(height: 12),
                  if (_selectedClient != null)
                    Container(
                      width: double.infinity,
                      padding: const EdgeInsets.all(14),
                      decoration: BoxDecoration(
                        color: const Color(0xFFE6FFFB),
                        borderRadius: BorderRadius.circular(12),
                        border: Border.all(color: _primary),
                      ),
                      child: Text(
                        'Client régulier : ${_selectedClient!.loyaltyCount} visite${_selectedClient!.loyaltyCount > 1 ? 's' : ''}',
                        style: const TextStyle(
                          fontWeight: FontWeight.w800,
                          color: _primaryDark,
                        ),
                      ),
                    ),
                  if (_selectedClient != null) const SizedBox(height: 12),
                  SwitchListTile(
                    activeThumbColor: _primary,
                    title: const Text(
                      'Réservation via Booking.com',
                      style: TextStyle(fontWeight: FontWeight.bold),
                    ),
                    value: _isBookingCom,
                    onChanged: (val) {
                      setState(() {
                        _isBookingCom = val;
                        _selectedRooms.clear(); // Clear selection when toggling
                      });
                    },
                  ),
                  const Divider(),
                  ListTile(
                    title: Text(
                      'Arrivée : ${_checkIn.toIso8601String().substring(0, 10)}',
                    ),
                    trailing: const Icon(Icons.calendar_today),
                    onTap: () async {
                      var d = await showDatePicker(
                        context: context,
                        initialDate: _checkIn,
                        firstDate: DateTime.now().subtract(
                          const Duration(days: 1),
                        ),
                        lastDate: DateTime.now().add(const Duration(days: 365)),
                      );
                      if (d != null) {
                        setState(() {
                          _checkIn = d;
                          _checkOut = d.add(
                            const Duration(days: 1),
                          ); // Auto-ajustement départ
                        });
                        _fetchData();
                      }
                    },
                  ),
                  ListTile(
                    title: Text(
                      'Départ : ${_checkOut.toIso8601String().substring(0, 10)}',
                    ),
                    trailing: const Icon(Icons.calendar_today),
                    onTap: () async {
                      var d = await showDatePicker(
                        context: context,
                        initialDate: _checkOut,
                        firstDate: _checkIn.add(const Duration(days: 1)),
                        lastDate: DateTime.now().add(const Duration(days: 365)),
                      );
                      if (d != null) {
                        setState(() => _checkOut = d);
                        _fetchData();
                      }
                    },
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
                  const SizedBox(height: 20),
                  Container(
                    padding: const EdgeInsets.all(12),
                    decoration: BoxDecoration(
                      color: _primary.withValues(alpha: 0.08),
                      borderRadius: BorderRadius.circular(8),
                      border: Border.all(
                        color: _primary.withValues(alpha: 0.18),
                      ),
                    ),
                    child: Row(
                      mainAxisAlignment: MainAxisAlignment.spaceBetween,
                      children: [
                        const Text(
                          'Prix Total:',
                          style: TextStyle(
                            fontWeight: FontWeight.bold,
                            fontSize: 16,
                          ),
                        ),
                        Text(
                          '${formatPrice(_calculateTotalPrice())} Ar',
                          style: const TextStyle(
                            fontWeight: FontWeight.bold,
                            fontSize: 18,
                            color: _primaryDark,
                          ),
                        ),
                      ],
                    ),
                  ),
                  const SizedBox(height: 20),
                  SizedBox(
                    width: double.infinity,
                    child: ElevatedButton(
                      onPressed: _saveBooking,
                      child: const Text('Enregistrer la réservation'),
                    ),
                  ),
                ],
              ),
            ),
          ),
          VerticalDivider(color: Colors.grey.shade300),
          Expanded(
            flex: 2,
            child: Padding(
              padding: const EdgeInsets.all(16.0),
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  Row(
                    mainAxisAlignment: MainAxisAlignment.spaceBetween,
                    children: [
                      const Text(
                        'Chambres libres et tarifs',
                        style: TextStyle(
                          fontSize: 16,
                          fontWeight: FontWeight.w900,
                          color: _ink,
                        ),
                      ),
                      IconButton(
                        onPressed: _fetchData,
                        icon: const Icon(Icons.refresh),
                      ),
                    ],
                  ),
                  const SizedBox(height: 12),
                  if (_loadingRooms)
                    const Center(child: CircularProgressIndicator()),
                  if (!_loadingRooms)
                    Expanded(
                      child: ListView.builder(
                        itemCount: filteredRooms.length,
                        itemBuilder: (context, index) {
                          final room = filteredRooms[index];
                          final isSelected = _selectedRooms.any(
                            (r) => r['id'] == room['id'],
                          );
                          String roomLabel =
                              'Chambre ${room['room_number']} — ${room['type']} (${room['model']})';
                          final fixedPrice = _getFixedPrice(room);
                          final isFixedPrice = room['is_fixed_price'] == true;
                          final aiPrice = _isBookingCom
                              ? 162500
                              : _getSuggestedPrice(room);

                          return CheckboxListTile(
                            title: Text(
                              roomLabel,
                              style: TextStyle(
                                fontWeight:
                                    room['model'].toString().contains(
                                      'Supérieure',
                                    )
                                    ? FontWeight.bold
                                    : FontWeight.normal,
                              ),
                            ),
                            subtitle: Padding(
                              padding: const EdgeInsets.only(top: 4),
                              child: Column(
                                crossAxisAlignment: CrossAxisAlignment.start,
                                children: [
                                  Text(
                                    'Prix fixe : ${formatPrice(fixedPrice)} Ar / nuit',
                                    style: const TextStyle(
                                      color: _primaryDark,
                                      fontWeight: FontWeight.w900,
                                    ),
                                  ),
                                  Text(
                                    'Prix ajusté (IA) : ${formatPrice(aiPrice)} Ar / nuit${isFixedPrice ? ' (non ajustable)' : ''}',
                                    style: TextStyle(
                                      fontWeight: FontWeight.w600,
                                      color: _muted,
                                      fontStyle: FontStyle.italic,
                                    ),
                                  ),
                                ],
                              ),
                            ),
                            value: isSelected,
                            onChanged: (val) {
                              setState(() {
                                if (val == true) {
                                  _selectedRooms.add(room);
                                } else {
                                  _selectedRooms.removeWhere(
                                    (r) => r['id'] == room['id'],
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
