import 'dart:async';
import 'dart:convert';
import 'dart:ui';

import 'package:flutter/material.dart';
import 'package:http/http.dart' as http;
import 'package:shared_preferences/shared_preferences.dart';
import 'package:url_launcher/url_launcher.dart';

import 'core/app_config.dart';
import 'core/formatters.dart';
import 'models/client_profile.dart';
import 'models/organization_profile.dart';
import 'models/app_user.dart';
import 'screens/admin_users_page.dart';
import 'screens/reservations_list_page.dart';
import 'screens/payment_summary_page.dart';
import 'services/session_service.dart';
import 'widgets/availability_card.dart';
import 'widgets/client_autocomplete_field.dart';
import 'widgets/organization_autocomplete_field.dart';

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

  ErrorWidget.builder = (details) {
    return MaterialApp(
      debugShowCheckedModeBanner: false,
      home: Scaffold(
        backgroundColor: _pageBg,
        body: Center(
          child: ConstrainedBox(
            constraints: const BoxConstraints(maxWidth: 460),
            child: Card(
              child: Padding(
                padding: const EdgeInsets.all(24),
                child: Column(
                  mainAxisSize: MainAxisSize.min,
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    const Icon(
                      Icons.shield_moon_outlined,
                      color: _rose,
                      size: 34,
                    ),
                    const SizedBox(height: 14),
                    const Text(
                      'Mode de secours',
                      style: TextStyle(
                        fontSize: 24,
                        fontWeight: FontWeight.w900,
                        color: _ink,
                      ),
                    ),
                    const SizedBox(height: 8),
                    const Text(
                      'L’interface a rencontré un problème. Ferme et rouvre l’app pour repartir sur une base propre.',
                      style: TextStyle(
                        color: _muted,
                        fontWeight: FontWeight.w600,
                      ),
                    ),
                    const SizedBox(height: 14),
                    Text(
                      details.exceptionAsString(),
                      maxLines: 4,
                      overflow: TextOverflow.ellipsis,
                      style: const TextStyle(fontSize: 12, color: _muted),
                    ),
                  ],
                ),
              ),
            ),
          ),
        ),
      ),
    );
  };

  runZonedGuarded(
    () {
      runApp(KamoroApp(initialUser: initialUser));
    },
    (error, stack) {
      debugPrint('Unhandled app error: $error');
      debugPrint(stack.toString());
    },
  );
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

class _RoomSegmentDraft {
  _RoomSegmentDraft({
    required this.roomId,
    required this.roomLabel,
    required this.startDate,
    required this.endDate,
    required this.extraBeds,
    required this.extraMattresses,
  });

  int roomId;
  String roomLabel;
  DateTime startDate;
  DateTime endDate;
  int extraBeds;
  int extraMattresses;
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
      body: Container(
        decoration: const BoxDecoration(
          gradient: LinearGradient(
            begin: Alignment.topLeft,
            end: Alignment.bottomRight,
            colors: [_pageBg, _sand],
          ),
        ),
        child: Center(
          child: SingleChildScrollView(
            padding: const EdgeInsets.all(20),
            child: ConstrainedBox(
              constraints: const BoxConstraints(maxWidth: 430),
              child: Card(
                child: Padding(
                  padding: const EdgeInsets.fromLTRB(28, 30, 28, 28),
                  child: Column(
                    mainAxisSize: MainAxisSize.min,
                    crossAxisAlignment: CrossAxisAlignment.stretch,
                    children: [
                      Center(
                        child: Container(
                          width: 132,
                          height: 96,
                          padding: const EdgeInsets.all(10),
                          decoration: BoxDecoration(
                            borderRadius: BorderRadius.circular(24),
                            color: _primary.withValues(alpha: 0.08),
                            border: Border.all(color: _border),
                            boxShadow: [
                              BoxShadow(
                                color: _primary.withValues(alpha: 0.08),
                                blurRadius: 20,
                                offset: const Offset(0, 8),
                              ),
                            ],
                          ),
                          child: ClipRRect(
                            borderRadius: BorderRadius.circular(18),
                            child: Image.asset(
                              'assets/login_logo.png',
                              fit: BoxFit.contain,
                              filterQuality: FilterQuality.high,
                            ),
                          ),
                        ),
                      ),
                      const SizedBox(height: 18),
                      const Text(
                        'Kamoro Hotel',
                        textAlign: TextAlign.center,
                        style: TextStyle(
                          fontSize: 30,
                          fontWeight: FontWeight.w900,
                          color: _ink,
                        ),
                      ),
                      const SizedBox(height: 6),
                      const Text(
                        'Espace réception et gestion',
                        textAlign: TextAlign.center,
                        style: TextStyle(
                          color: _muted,
                          fontWeight: FontWeight.w600,
                        ),
                      ),
                      const SizedBox(height: 8),
                      Align(
                        alignment: Alignment.center,
                        child: Container(
                          padding: const EdgeInsets.symmetric(
                            horizontal: 12,
                            vertical: 6,
                          ),
                          decoration: BoxDecoration(
                            color: _sand.withValues(alpha: 0.85),
                            borderRadius: BorderRadius.circular(999),
                            border: Border.all(color: _border),
                          ),
                          child: const Text(
                            'Hestia Predict',
                            style: TextStyle(
                              fontSize: 11,
                              fontWeight: FontWeight.w800,
                              color: _primaryDark,
                              letterSpacing: 0.5,
                            ),
                          ),
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
                                  border: Border.all(
                                    color: Colors.red.shade100,
                                  ),
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
    if (!isSilent && mounted) setState(() => _isLoading = true);
    if (mounted) {
      setState(() => _errorMessage = '');
    }
    String dateStr = _selectedDate.toIso8601String().substring(0, 10);

    try {
      final availResp = await http
          .get(Uri.parse('$baseUrl/api/live-availability?date=$dateStr'))
          .timeout(const Duration(seconds: 5));
      if (!mounted) return;
      if (availResp.statusCode == 200) {
        setState(() {
          _categories = json.decode(availResp.body);
          if (!isSilent) _isLoading = false;
        });
      } else {
        _useFallbackData('Erreur serveur: ${availResp.statusCode}');
        if (!isSilent && mounted) setState(() => _isLoading = false);
      }
    } catch (e) {
      debugPrint("$e");
      if (!mounted) return;
      _useFallbackData('Mode Hors-ligne : Serveur injoignable.');
      if (!isSilent && mounted) setState(() => _isLoading = false);
    }

    http
        .get(
          Uri.parse(
            '$baseUrl/api/dashboard/reservation-status-summary?date=$dateStr',
          ),
        )
        .then((summaryResp) {
          if (!mounted || summaryResp.statusCode != 200) return;
          final summary = json.decode(summaryResp.body);
          setState(() {
            _pendingGuestsCount = summary['pending'] is num
                ? summary['pending'].toInt()
                : 0;
            _arrivedGuestsCount = summary['arrived'] is num
                ? summary['arrived'].toInt()
                : 0;
          });
        })
        .catchError((e) {
          debugPrint("Reservation status summary fetch error: $e");
        });

    http
        .get(Uri.parse('$baseUrl/api/dashboard/predictions?days=30'))
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
              onSummaryTap: () {
                Navigator.pop(context);
                Navigator.push(
                  context,
                  _softRoute(
                    PaymentSummaryPage(
                      role: widget.role,
                      userName: widget.userName,
                    ),
                  ),
                );
              },
              onDashboardTap: () {
                Navigator.pop(context);
                _launchURL('http://localhost:8000/dashboard');
              },
              onManageStaff: () {
                Navigator.pop(context);
                Navigator.push(
                  context,
                  _softRoute(AdminUsersPage(currentRole: widget.role)),
                );
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
                  onSummaryTap: () => Navigator.push(
                    context,
                    _softRoute(
                      PaymentSummaryPage(
                        role: widget.role,
                        userName: widget.userName,
                      ),
                    ),
                  ),
                  onManageStaff: () => Navigator.push(
                    context,
                    _softRoute(AdminUsersPage(currentRole: widget.role)),
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
          _softRoute(
            NewBookingPage(userName: widget.userName, role: widget.role),
          ),
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
    required this.onSummaryTap,
    required this.onDashboardTap,
    required this.onManageStaff,
    required this.onLogout,
  });

  final String role;
  final String userName;
  final VoidCallback onSummaryTap;
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
              role == 'superadmin'
                  ? 'Super administrateur'
                  : (role == 'admin' ? 'Administrateur' : 'Réceptionniste'),
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
          ListTile(
            leading: const Icon(Icons.receipt_long_outlined),
            title: const Text('Récapitulatif'),
            onTap: onSummaryTap,
          ),
          if (role != 'receptionist') ...[
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
    required this.onSummaryTap,
    required this.onDashboardTap,
    required this.onManageStaff,
    required this.onLogout,
  });

  final String role;
  final String userName;
  final VoidCallback onSummaryTap;
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
                const SizedBox(height: 10),
                _SideNavButton(
                  icon: Icons.receipt_long_outlined,
                  label: 'Récapitulatif',
                  onTap: onSummaryTap,
                ),
                if (role != 'receptionist') ...[
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
                              role == 'superadmin'
                                  ? 'Super administrateur'
                                  : (role == 'admin'
                                        ? 'Administrateur'
                                        : 'Réceptionniste'),
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
    final keyCandidates = _predictionKeyCandidates(category);
    String dateStr = selectedDate.toIso8601String().substring(0, 10);

    for (final key in keyCandidates) {
      final predictions = aiPredictions[key];
      if (predictions is! List) continue;
      final prediction = predictions.firstWhere(
        (p) => p['date'] == dateStr,
        orElse: () => null,
      );
      if (prediction != null) {
        return prediction['adjusted_price_ariary'] ??
            prediction['suggested_price_ariary'];
      }
    }

    final normalizedCandidates = keyCandidates
        .map(_normalizeKey)
        .where((value) => value.isNotEmpty);
    for (final entry in aiPredictions.entries) {
      final entryKey = _normalizeKey(entry.key.toString());
      if (entry.value is! List) continue;
      if (!normalizedCandidates.any(
        (candidate) =>
            entryKey == candidate ||
            entryKey.contains(candidate) ||
            candidate.contains(entryKey),
      )) {
        continue;
      }
      final predictions = entry.value as List<dynamic>;
      final prediction = predictions.firstWhere(
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

  List<String> _predictionKeyCandidates(dynamic category) {
    final type = (category['type'] ?? '').toString().trim();
    final model = (category['model'] ?? '').toString().trim();
    final identifier = (category['identifier'] ?? '').toString().trim();
    final raw = <String>[
      if (identifier.isNotEmpty) identifier,
      if (type.isNotEmpty && model.isNotEmpty) '$type - $model',
      if (type.isNotEmpty && model.isNotEmpty) '$type $model',
      if (type.isNotEmpty) type,
      if (model.isNotEmpty) model,
    ];
    final seen = <String>{};
    return raw.where((value) => seen.add(value)).toList();
  }

  String _normalizeKey(String input) {
    var value = input.toLowerCase().trim();
    value = value.replaceAll(RegExp(r'\s+'), ' ');
    const replacements = {
      'à': 'a',
      'â': 'a',
      'ä': 'a',
      'á': 'a',
      'ã': 'a',
      'ç': 'c',
      'é': 'e',
      'è': 'e',
      'ê': 'e',
      'ë': 'e',
      'î': 'i',
      'ï': 'i',
      'ô': 'o',
      'ö': 'o',
      'ù': 'u',
      'û': 'u',
      'ü': 'u',
      'ÿ': 'y',
      'œ': 'oe',
    };
    replacements.forEach((from, to) => value = value.replaceAll(from, to));
    value = value.replaceAll(RegExp(r'[^a-z0-9 -]'), '');
    return value;
  }

  int _categoryRank(dynamic category) {
    final text = _normalizeKey(
      '${category['type'] ?? ''} ${category['model'] ?? ''}',
    );
    if (text.contains('double')) return 0;
    if (text.contains('twin')) return 1;
    if (text.contains('triple')) return 2;
    if (text.contains('famil')) return 3;
    if (text.contains('suite')) return 4;
    return 5;
  }

  String _categoryGroupLabel(dynamic category) {
    final text = _normalizeKey(
      '${category['type'] ?? ''} ${category['model'] ?? ''}',
    );
    if (text.contains('double')) return 'Chambres doubles';
    if (text.contains('twin')) return 'Chambres twin';
    if (text.contains('triple')) return 'Chambres triples';
    if (text.contains('famil')) return 'Chambres familiales';
    if (text.contains('suite')) return 'Suites';
    return 'Autres chambres';
  }

  List<Map<String, dynamic>> _sortedCategories() {
    final sorted = categories
        .map((cat) => Map<String, dynamic>.from(cat))
        .toList();
    sorted.sort((a, b) {
      final rankA = _categoryRank(a);
      final rankB = _categoryRank(b);
      if (rankA != rankB) return rankA.compareTo(rankB);
      final typeA = _normalizeKey((a['type'] ?? '').toString());
      final typeB = _normalizeKey((b['type'] ?? '').toString());
      final byType = typeA.compareTo(typeB);
      if (byType != 0) return byType;
      final modelA = _normalizeKey((a['model'] ?? '').toString());
      final modelB = _normalizeKey((b['model'] ?? '').toString());
      return modelA.compareTo(modelB);
    });
    return sorted;
  }

  List<_RoomCategoryGroup> _groupedCategories() {
    final grouped = <String, List<Map<String, dynamic>>>{};
    for (final category in _sortedCategories()) {
      final label = _categoryGroupLabel(category);
      grouped.putIfAbsent(label, () => []);
      grouped[label]!.add(category);
    }

    const order = [
      'Chambres doubles',
      'Chambres twin',
      'Chambres triples',
      'Chambres familiales',
      'Suites',
      'Autres chambres',
    ];

    return order
        .where((label) => grouped.containsKey(label))
        .map(
          (label) => _RoomCategoryGroup(title: label, items: grouped[label]!),
        )
        .toList();
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
    final groupedCategories = _groupedCategories();

    return ListView.separated(
      key: const ValueKey('grid'),
      padding: const EdgeInsets.fromLTRB(0, 0, 0, 96),
      itemCount: groupedCategories.length,
      separatorBuilder: (context, index) => const SizedBox(height: 22),
      itemBuilder: (context, groupIndex) {
        final group = groupedCategories[groupIndex];
        return Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Text(
              group.title,
              style: const TextStyle(
                color: _ink,
                fontSize: 17,
                fontWeight: FontWeight.w900,
              ),
            ),
            const SizedBox(height: 12),
            SingleChildScrollView(
              scrollDirection: Axis.horizontal,
              child: Row(
                children: List.generate(group.items.length, (index) {
                  final cat = group.items[index];
                  final suggestedPrice = _getAiSuggestedPrice(cat);
                  return Padding(
                    padding: EdgeInsets.only(
                      right: index == group.items.length - 1 ? 0 : 16,
                    ),
                    child: TweenAnimationBuilder<double>(
                      tween: Tween(begin: 0, end: 1),
                      duration: Duration(
                        milliseconds: 240 + (index.clamp(0, 8) * 35),
                      ),
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
                      child: SizedBox(
                        width: 300,
                        child: AvailabilityCard(
                          category: Map<String, dynamic>.from(cat),
                          suggestedPrice: suggestedPrice,
                        ),
                      ),
                    ),
                  );
                }),
              ),
            ),
          ],
        );
      },
    );
  }
}

class _RoomCategoryGroup {
  const _RoomCategoryGroup({required this.title, required this.items});

  final String title;
  final List<Map<String, dynamic>> items;
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

class _SummaryLine extends StatelessWidget {
  const _SummaryLine({
    required this.label,
    required this.value,
    this.emphasized = false,
  });

  final String label;
  final String value;
  final bool emphasized;

  @override
  Widget build(BuildContext context) {
    final labelStyle = TextStyle(
      color: emphasized ? _primaryDark : _muted,
      fontWeight: FontWeight.w800,
      fontSize: emphasized ? 15 : 13,
    );
    final valueStyle = TextStyle(
      color: _primaryDark,
      fontWeight: FontWeight.w900,
      fontSize: emphasized ? 18 : 14,
    );

    return Row(
      mainAxisAlignment: MainAxisAlignment.spaceBetween,
      children: [
        Text(label, style: labelStyle),
        Text(value, style: valueStyle),
      ],
    );
  }
}

class _QuantitySelector extends StatelessWidget {
  const _QuantitySelector({
    required this.icon,
    required this.label,
    required this.unitPrice,
    required this.stayNights,
    required this.value,
    required this.onChanged,
    this.maxValue,
  });

  final IconData icon;
  final String label;
  final int unitPrice;
  final int stayNights;
  final int value;
  final ValueChanged<int> onChanged;
  final int? maxValue;

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
                if (maxValue != null)
                  Text(
                    'Restant : $maxValue',
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
            onPressed: maxValue != null && value >= maxValue!
                ? null
                : () => onChanged(value + 1),
            icon: const Icon(Icons.add_circle_outline),
          ),
        ],
      ),
    );
  }
}

class NewBookingPage extends StatefulWidget {
  final String userName;
  final String role;
  const NewBookingPage({super.key, required this.userName, required this.role});
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
  final List<_RoomSegmentDraft> _segmentDrafts = [];
  String _roomSearchQuery = '';
  bool _showRoomsNeedingSplit = false;
  int _remainingExtraBeds = 6;
  int _remainingExtraMattresses = 6;
  bool _loadingRooms = false;
  bool _savingBooking = false;
  bool _isBookingCom = false;
  bool _isOrganizationBooking = false;
  ClientProfile? _selectedClient;
  OrganizationProfile? _selectedOrganization;
  bool _suppressSelectedClientReset = false;
  final _organizationContactNameController = TextEditingController();
  final _organizationContactPhoneController = TextEditingController();
  final _organizationEmailController = TextEditingController();
  final _organizationBillingAddressController = TextEditingController();
  final _organizationNifController = TextEditingController();
  final _organizationStatController = TextEditingController();

  @override
  void initState() {
    super.initState();
    _nameController.addListener(_handleClientTextChanged);
    _phoneController.addListener(_handleClientTextChanged);
    WidgetsBinding.instance.addPostFrameCallback((_) {
      if (mounted) {
        _showReservationTypeDialog();
      }
    });
  }

  @override
  void dispose() {
    _nameController.removeListener(_handleClientTextChanged);
    _phoneController.removeListener(_handleClientTextChanged);
    _nameController.dispose();
    _phoneController.dispose();
    _emailController.dispose();
    _organizationContactNameController.dispose();
    _organizationContactPhoneController.dispose();
    _organizationEmailController.dispose();
    _organizationBillingAddressController.dispose();
    _organizationNifController.dispose();
    _organizationStatController.dispose();
    super.dispose();
  }

  String _clientName() {
    return _nameController.text.trim();
  }

  String get _reservationNameLabel =>
      _isOrganizationBooking ? 'Nom de l’organisme' : 'Nom du client';

  int _asInt(dynamic value) {
    if (value is int) return value;
    if (value is num) return value.toInt();
    return int.tryParse(value?.toString() ?? '') ?? 0;
  }

  DateTime _dateOnly(DateTime date) =>
      DateTime(date.year, date.month, date.day);

  String _formatShortDate(DateTime date) {
    final normalized = _dateOnly(date);
    return '${normalized.day.toString().padLeft(2, '0')}/${normalized.month.toString().padLeft(2, '0')}';
  }

  String _roomLabel(dynamic room) {
    final roomNumber = (room['room_number'] ?? room['number'] ?? room['id'])
        .toString()
        .trim();
    final type = (room['type'] ?? '').toString().trim();
    final model = (room['model'] ?? '').toString().trim();
    final base = roomNumber.isEmpty
        ? [type, model].where((value) => value.isNotEmpty).join(' - ')
        : (type.isEmpty ? roomNumber : '$roomNumber - $type');
    if (base.isEmpty) return 'Chambre';
    return model.isEmpty ? base : '$base ($model)';
  }

  String _roomCategory(dynamic room) {
    final raw = [room['type'], room['model'], room['room_number']]
        .where((value) => value != null)
        .map((value) => value.toString())
        .join(' ')
        .toLowerCase();
    if (raw.contains('standard') || raw.contains('standart')) return 'standard';
    if (raw.contains('supérieure') ||
        raw.contains('superieure') ||
        raw.contains('superior')) {
      return 'superior';
    }
    if (raw.contains('famil')) return 'family';
    if (raw.contains('suite')) return 'suite';
    return raw.trim().isEmpty ? 'other' : raw.trim();
  }

  int _roomCategoryRank(dynamic room) {
    switch (_roomCategory(room)) {
      case 'standard':
        return 0;
      case 'superior':
        return 1;
      case 'family':
        return 2;
      case 'suite':
        return 3;
      default:
        return 4;
    }
  }

  bool _roomCoversRange(dynamic room, DateTime start, DateTime end) {
    final normalizedStart = _dateOnly(start);
    final normalizedEnd = _dateOnly(end);
    if (!normalizedEnd.isAfter(normalizedStart)) return false;

    for (final segment in _roomAvailabilitySegments(room)) {
      final segmentStart = DateTime.tryParse(
        segment['segment_start_date']?.toString() ?? '',
      );
      final segmentEnd = DateTime.tryParse(
        segment['segment_end_date']?.toString() ?? '',
      );
      if (segmentStart == null || segmentEnd == null) continue;
      final freeStart = _dateOnly(segmentStart);
      final freeEnd = _dateOnly(segmentEnd);
      if (!normalizedStart.isBefore(freeStart) &&
          !normalizedEnd.isAfter(freeEnd)) {
        return true;
      }
    }

    return false;
  }

  int _coverageNights(dynamic room) {
    var nights = 0;
    final start = _dateOnly(_checkIn);
    final end = _dateOnly(_checkOut);
    var cursor = start;
    while (cursor.isBefore(end)) {
      if (_roomCoversRange(room, cursor, cursor.add(const Duration(days: 1)))) {
        nights++;
      }
      cursor = cursor.add(const Duration(days: 1));
    }
    return nights;
  }

  bool _wasOccupiedPreviousNight(dynamic room) {
    return room is Map && room['occupied_previous_night'] == true;
  }

  int _compareFallbackRooms(
    Map<String, dynamic> a,
    Map<String, dynamic> b,
    Map<String, dynamic> preferredRoom,
  ) {
    final previousNight = (_wasOccupiedPreviousNight(a) ? 1 : 0).compareTo(
      _wasOccupiedPreviousNight(b) ? 1 : 0,
    );
    if (previousNight != 0) return previousNight;

    final preferredCategory = _roomCategory(preferredRoom);
    final aSameCategory = _roomCategory(a) == preferredCategory;
    final bSameCategory = _roomCategory(b) == preferredCategory;
    if (aSameCategory != bSameCategory) return aSameCategory ? -1 : 1;

    final aCategory = _roomCategoryRank(a);
    final bCategory = _roomCategoryRank(b);
    if (aCategory != bCategory) return aCategory.compareTo(bCategory);

    final aScore = _coverageNights(a);
    final bScore = _coverageNights(b);
    if (aScore != bScore) return aScore.compareTo(bScore);

    return _roomLabel(a).compareTo(_roomLabel(b));
  }

  bool _shouldDisplayRoom(dynamic room) {
    final isSelected = _selectedRooms.any(
      (selected) => _asInt(selected['id']) == _asInt(room['id']),
    );
    if (isSelected) return true;
    if (room['is_fully_available'] == true) return true;
    return _showRoomsNeedingSplit && _roomAvailabilitySegments(room).isNotEmpty;
  }

  List<DateTime> _stayNightsList() {
    final nights = <DateTime>[];
    var cursor = _dateOnly(_checkIn);
    final end = _dateOnly(_checkOut);
    while (cursor.isBefore(end)) {
      nights.add(cursor);
      cursor = cursor.add(const Duration(days: 1));
    }
    return nights;
  }

  List<_RoomSegmentDraft> _buildAutoSegmentDrafts() {
    if (_selectedRooms.isEmpty) return [];

    final selectedRooms = _selectedRooms
        .map(
          (room) => room is Map<String, dynamic>
              ? room
              : Map<String, dynamic>.from(room as Map),
        )
        .toList();

    final fallbackRooms = _allAvailableRooms
        .where(
          (room) => !selectedRooms.any(
            (selected) => _asInt(selected['id']) == _asInt(room['id']),
          ),
        )
        .map(
          (room) => room is Map<String, dynamic>
              ? room
              : Map<String, dynamic>.from(room as Map),
        )
        .toList();
    final allSelectedCoverFullStay = selectedRooms.every(
      (room) => _roomCoversRange(room, _checkIn, _checkOut),
    );
    if (allSelectedCoverFullStay) {
      return selectedRooms
          .map(
            (room) => _RoomSegmentDraft(
              roomId: _asInt(room['id']),
              roomLabel: _roomLabel(room),
              startDate: _checkIn,
              endDate: _checkOut,
              extraBeds: 0,
              extraMattresses: 0,
            ),
          )
          .toList();
    }

    final nights = _stayNightsList();
    final slotCount = selectedRooms.length;
    final slotCurrentRooms = List<Map<String, dynamic>?>.filled(
      slotCount,
      null,
      growable: false,
    );
    final slotCurrentStarts = List<DateTime?>.filled(
      slotCount,
      null,
      growable: false,
    );
    final segments = <_RoomSegmentDraft>[];

    for (final night in nights) {
      final usedThisNight = <int>{};
      for (var slotIndex = 0; slotIndex < slotCount; slotIndex++) {
        final preferredRoom = selectedRooms[slotIndex];
        final preferredId = _asInt(preferredRoom['id']);
        final currentRoom = slotCurrentRooms[slotIndex];
        final currentRoomId = currentRoom == null
            ? 0
            : _asInt(currentRoom['id']);
        final currentRoomIsFallback =
            currentRoom != null &&
            !selectedRooms.any((room) => _asInt(room['id']) == currentRoomId);

        late Map<String, dynamic> chosenRoom;
        var hasChosenRoom = false;
        if (_roomCoversRange(
              preferredRoom,
              night,
              night.add(const Duration(days: 1)),
            ) &&
            !usedThisNight.contains(preferredId)) {
          chosenRoom = preferredRoom;
          hasChosenRoom = true;
        } else if (currentRoomIsFallback &&
            _roomCoversRange(
              currentRoom,
              night,
              night.add(const Duration(days: 1)),
            ) &&
            !usedThisNight.contains(currentRoomId)) {
          chosenRoom = currentRoom;
          hasChosenRoom = true;
        } else {
          final candidates = fallbackRooms
              .where(
                (room) =>
                    !usedThisNight.contains(_asInt(room['id'])) &&
                    _roomCoversRange(
                      room,
                      night,
                      night.add(const Duration(days: 1)),
                    ),
              )
              .toList();
          if (candidates.isEmpty) {
            return [];
          }
          candidates.sort((a, b) => _compareFallbackRooms(a, b, preferredRoom));
          chosenRoom = candidates.first;
          hasChosenRoom = true;
        }

        if (!hasChosenRoom) {
          return [];
        }

        final chosenId = _asInt(chosenRoom['id']);
        if (slotCurrentRooms[slotIndex] == null ||
            _asInt(slotCurrentRooms[slotIndex]!['id']) != chosenId) {
          if (slotCurrentRooms[slotIndex] != null &&
              slotCurrentStarts[slotIndex] != null) {
            segments.add(
              _RoomSegmentDraft(
                roomId: _asInt(slotCurrentRooms[slotIndex]!['id']),
                roomLabel: _roomLabel(slotCurrentRooms[slotIndex]!),
                startDate: slotCurrentStarts[slotIndex]!,
                endDate: night,
                extraBeds: 0,
                extraMattresses: 0,
              ),
            );
          }
          slotCurrentRooms[slotIndex] = chosenRoom;
          slotCurrentStarts[slotIndex] = night;
        }

        usedThisNight.add(chosenId);
      }
    }

    for (var slotIndex = 0; slotIndex < slotCount; slotIndex++) {
      final room = slotCurrentRooms[slotIndex];
      final start = slotCurrentStarts[slotIndex];
      if (room != null && start != null) {
        segments.add(
          _RoomSegmentDraft(
            roomId: _asInt(room['id']),
            roomLabel: _roomLabel(room),
            startDate: start,
            endDate: _checkOut,
            extraBeds: 0,
            extraMattresses: 0,
          ),
        );
      }
    }

    return segments;
  }

  void _rebuildSegmentDraftsFromSelection() {
    _segmentDrafts
      ..clear()
      ..addAll(_buildAutoSegmentDrafts());
  }

  void _syncSelectionFromDrafts() {
    final seen = <int>{};
    final nextSelected = <dynamic>[];
    for (final draft in _segmentDrafts) {
      if (!seen.add(draft.roomId)) continue;
      final room = _roomById(draft.roomId);
      if (room != null) {
        nextSelected.add(room);
      }
    }
    _selectedRooms
      ..clear()
      ..addAll(nextSelected);
  }

  List<Map<String, dynamic>> _compatibleRoomsForDraft(_RoomSegmentDraft draft) {
    final rooms = _allAvailableRooms
        .where((room) => _roomCoversRange(room, draft.startDate, draft.endDate))
        .map(
          (room) => room is Map<String, dynamic>
              ? room
              : Map<String, dynamic>.from(room as Map),
        )
        .toList();
    rooms.sort((a, b) {
      final rank = _roomCategoryRank(a).compareTo(_roomCategoryRank(b));
      if (rank != 0) return rank;
      return _roomLabel(a).compareTo(_roomLabel(b));
    });
    return rooms;
  }

  void _updateDraftRoom(_RoomSegmentDraft draft, int roomId) {
    final room = _roomById(roomId);
    if (room == null) return;
    setState(() {
      draft.roomId = roomId;
      draft.roomLabel = _roomLabel(room);
      _syncSelectionFromDrafts();
    });
  }

  void _updateDraftStartDate(_RoomSegmentDraft draft, DateTime value) {
    setState(() {
      draft.startDate = _dateOnly(value);
      if (!draft.endDate.isAfter(draft.startDate)) {
        draft.endDate = draft.startDate.add(const Duration(days: 1));
      }
      _syncSelectionFromDrafts();
    });
  }

  void _updateDraftEndDate(_RoomSegmentDraft draft, DateTime value) {
    setState(() {
      draft.endDate = _dateOnly(value);
      if (!draft.endDate.isAfter(draft.startDate)) {
        draft.endDate = draft.startDate.add(const Duration(days: 1));
      }
      _syncSelectionFromDrafts();
    });
  }

  int _segmentNightCount(_RoomSegmentDraft draft) {
    final nights = draft.endDate.difference(draft.startDate).inDays;
    return nights < 1 ? 1 : nights;
  }

  int _segmentRoomNightPrice(_RoomSegmentDraft draft) {
    final room = _roomById(draft.roomId);
    return room == null ? 0 : _getSuggestedPrice(room);
  }

  int _segmentExtrasNightPrice(_RoomSegmentDraft draft) {
    return (draft.extraBeds * 50000) + (draft.extraMattresses * 30000);
  }

  List<_RoomSegmentDraft> _currentSegmentDrafts() {
    if (_segmentDrafts.isNotEmpty) return _segmentDrafts;
    return _buildAutoSegmentDrafts();
  }

  bool _coversWholeStay(List<_RoomSegmentDraft> drafts) {
    final covered = <String>{};
    for (final draft in drafts) {
      var cursor = _dateOnly(draft.startDate);
      final end = _dateOnly(draft.endDate);
      while (cursor.isBefore(end)) {
        covered.add(cursor.toIso8601String().substring(0, 10));
        cursor = cursor.add(const Duration(days: 1));
      }
    }

    for (final night in _stayNightsList()) {
      final key = night.toIso8601String().substring(0, 10);
      if (!covered.contains(key)) return false;
    }
    return true;
  }

  Future<void> _showReservationTypeDialog() async {
    if (_selectedClient != null || _selectedOrganization != null) return;

    String choice = 'individual';
    final confirmed = await showDialog<bool>(
      context: context,
      barrierDismissible: false,
      builder: (context) => AlertDialog(
        title: const Text('Type de réservation'),
        content: const Text(
          'Choisis si cette nouvelle réservation concerne un particulier ou un organisme.',
        ),
        actions: [
          StatefulBuilder(
            builder: (context, setDialogState) => Column(
              mainAxisSize: MainAxisSize.min,
              children: [
                RadioGroup<String>(
                  groupValue: choice,
                  onChanged: (value) {
                    if (value != null) {
                      setDialogState(() => choice = value);
                    }
                  },
                  child: const Column(
                    mainAxisSize: MainAxisSize.min,
                    children: [
                      RadioListTile<String>(
                        title: Text('Particulier'),
                        value: 'individual',
                      ),
                      RadioListTile<String>(
                        title: Text('Organisme'),
                        value: 'organization',
                      ),
                    ],
                  ),
                ),
              ],
            ),
          ),
          TextButton(
            onPressed: () => Navigator.pop(context, false),
            child: const Text('Annuler'),
          ),
          ElevatedButton(
            onPressed: () => Navigator.pop(context, true),
            child: const Text('Valider'),
          ),
        ],
      ),
    );

    if (!mounted || confirmed != true) return;
    setState(() {
      _isOrganizationBooking = choice == 'organization';
      _selectedClient = null;
      _selectedOrganization = null;
    });
    await _fetchData();
  }

  void _applyClient(ClientProfile client) {
    _suppressSelectedClientReset = true;
    setState(() {
      _selectedClient = client;
      _nameController.text = client.displayName;
      _phoneController.text = client.phoneNumber?.trim() ?? '';
    });
    _suppressSelectedClientReset = false;
    _warnIfClientAlreadyBooked(client);
  }

  void _applyOrganization(OrganizationProfile organization) {
    setState(() {
      _selectedOrganization = organization;
      _nameController.text = organization.name;
      _organizationContactNameController.text =
          organization.contactName?.trim() ?? '';
      _organizationContactPhoneController.text =
          organization.phone?.trim() ?? '';
      _organizationEmailController.text = organization.email?.trim() ?? '';
      _organizationBillingAddressController.text =
          organization.billingAddress?.trim() ?? '';
      _organizationNifController.text = organization.nif?.trim() ?? '';
      _organizationStatController.text = organization.stat?.trim() ?? '';
      _emailController.text =
          organization.contactEmail?.trim().isNotEmpty == true
          ? organization.contactEmail!.trim()
          : (organization.email?.trim() ?? '');
    });
  }

  void _handleClientTextChanged() {
    if (_suppressSelectedClientReset) return;
    final selected = _selectedClient;
    final selectedOrganization = _selectedOrganization;
    if (selected == null && selectedOrganization == null) return;

    final currentName = _nameController.text.trim();
    final currentPhone = _phoneController.text.trim();
    if (selectedOrganization != null &&
        (_isOrganizationBooking &&
            currentName != selectedOrganization.name.trim())) {
      setState(() => _selectedOrganization = null);
    }

    if (selected == null) return;

    final selectedName = selected.displayName.trim();
    final selectedPhone = selected.phoneNumber?.trim() ?? '';

    final matchesSelected =
        currentName == selectedName &&
        (selectedPhone.isEmpty || currentPhone == selectedPhone);

    if (!matchesSelected) {
      setState(() => _selectedClient = null);
    }
  }

  Future<void> _warnIfClientAlreadyBooked(ClientProfile client) async {
    final query = (client.phoneNumber ?? client.displayName).trim();
    if (query.length < 2) return;

    try {
      final response = await http.get(
        Uri.parse(
          '$baseUrl/api/dashboard/client-history?q=${Uri.encodeComponent(query)}',
        ),
      );
      if (!mounted || response.statusCode != 200) return;

      final decoded = json.decode(response.body);
      final rawList = decoded is Map<String, dynamic>
          ? (decoded['data'] as List<dynamic>? ?? const [])
          : (decoded as List<dynamic>? ?? const []);

      final selectedDate = _checkIn.toIso8601String().substring(0, 10);
      final sameDayReservations = rawList.whereType<Map>().where((item) {
        final status = (item['status'] ?? '').toString();
        final checkIn = (item['check_in_date'] ?? '').toString();
        return status != 'annule' && checkIn == selectedDate;
      }).toList();

      if (sameDayReservations.isEmpty || !mounted) return;

      final references = sameDayReservations
          .map((item) => (item['reference'] ?? '').toString())
          .where((ref) => ref.isNotEmpty)
          .take(4)
          .join(', ');

      await showDialog<void>(
        context: context,
        builder: (context) => AlertDialog(
          title: const Text('Client déjà réservé'),
          content: Text(
            references.isEmpty
                ? 'Cette personne a déjà une réservation sur cette date.'
                : 'Cette personne a déjà une réservation sur cette date: $references.',
          ),
          actions: [
            TextButton(
              onPressed: () => Navigator.pop(context),
              child: const Text('OK'),
            ),
          ],
        ),
      );
    } catch (_) {
      // Avertissement non bloquant.
    }
  }

  Future<void> _fetchData() async {
    setState(() {
      _loadingRooms = true;
      _selectedRooms.clear();
      _segmentDrafts.clear();
    });

    final prefs = await SharedPreferences.getInstance();
    final roomsCacheKey =
        'booking_rooms:${_checkIn.toIso8601String().substring(0, 10)}:${_checkOut.toIso8601String().substring(0, 10)}';
    const aiCacheKey = 'booking_ai_predictions';

    try {
      final roomsResp = await http
          .get(
            Uri.parse(
              '$baseUrl/api/available-room-suggestions?check_in=${_checkIn.toIso8601String().substring(0, 10)}&check_out=${_checkOut.toIso8601String().substring(0, 10)}',
            ),
          )
          .timeout(const Duration(seconds: 5));
      if (roomsResp.statusCode == 200) {
        _allAvailableRooms = json.decode(roomsResp.body);
        await prefs.setString(roomsCacheKey, json.encode(_allAvailableRooms));
      } else {
        await _loadFallbackRoomsFromCache(prefs, roomsCacheKey);
      }
    } catch (e) {
      debugPrint("Rooms fetch error: $e");
      await _loadFallbackRoomsFromCache(prefs, roomsCacheKey);
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
          await prefs.setString(aiCacheKey, json.encode(_aiPredictions));
        }
      } else {
        final cachedAi = prefs.getString(aiCacheKey);
        if (cachedAi != null) {
          _aiPredictions = json.decode(cachedAi);
        }
      }
    } catch (e) {
      debugPrint("AI fetch error: $e");
      final cachedAi = prefs.getString(aiCacheKey);
      _aiPredictions = cachedAi != null ? json.decode(cachedAi) : {};
    }

    await _fetchExtraCapacity();

    setState(() => _loadingRooms = false);
  }

  Future<void> _fetchExtraCapacity() async {
    try {
      final response = await http
          .get(
            Uri.parse(
              '$baseUrl/api/dashboard/extras-capacity?check_in=${_checkIn.toIso8601String().substring(0, 10)}&check_out=${_checkOut.toIso8601String().substring(0, 10)}',
            ),
          )
          .timeout(const Duration(seconds: 4));

      if (response.statusCode != 200) return;

      final decoded = json.decode(response.body);
      if (decoded is! Map<String, dynamic>) return;

      final remainingBeds = decoded['remaining_beds'];
      final remainingMattresses = decoded['remaining_mattresses'];
      if (!mounted) return;
      setState(() {
        _remainingExtraBeds = remainingBeds is num ? remainingBeds.toInt() : 6;
        _remainingExtraMattresses = remainingMattresses is num
            ? remainingMattresses.toInt()
            : 6;
      });
    } catch (e) {
      debugPrint("Extra capacity fetch error: $e");
    }
  }

  Future<void> _loadFallbackRoomsFromCache(
    SharedPreferences prefs,
    String roomsCacheKey,
  ) async {
    final cachedRooms = prefs.getString(roomsCacheKey);
    if (cachedRooms != null) {
      _allAvailableRooms = json.decode(cachedRooms);
      return;
    }

    try {
      final legacyResp = await http
          .get(
            Uri.parse(
              '$baseUrl/api/available-rooms?check_in=${_checkIn.toIso8601String().substring(0, 10)}&check_out=${_checkOut.toIso8601String().substring(0, 10)}',
            ),
          )
          .timeout(const Duration(seconds: 4));
      if (legacyResp.statusCode == 200) {
        final rooms = json.decode(legacyResp.body) as List<dynamic>;
        _allAvailableRooms = rooms.map((room) {
          final parsed = Map<String, dynamic>.from(room as Map);
          parsed['availability_segments'] = [
            {
              'segment_start_date': _checkIn.toIso8601String().substring(0, 10),
              'segment_end_date': _checkOut.toIso8601String().substring(0, 10),
            },
          ];
          parsed['is_fully_available'] = true;
          parsed['has_partial_availability'] = false;
          return parsed;
        }).toList();
        await prefs.setString(roomsCacheKey, json.encode(_allAvailableRooms));
      }
    } catch (e) {
      debugPrint("Legacy rooms fetch error: $e");
    }
  }

  Future<void> _saveBooking() async {
    if (_savingBooking) return;

    final effectivePhone = _isOrganizationBooking
        ? _phoneController.text.trim()
        : _phoneController.text.trim();
    final effectiveEmail = _emailController.text.trim();
    final currentDrafts = _currentSegmentDrafts();
    if (currentDrafts.any((draft) {
      final room = _roomById(draft.roomId);
      return room == null ||
          !_roomCoversRange(room, draft.startDate, draft.endDate);
    })) {
      ScaffoldMessenger.of(context).showSnackBar(
        const SnackBar(
          content: Text(
            'Un des segments n’est plus compatible avec la chambre choisie.',
          ),
          backgroundColor: Colors.orange,
        ),
      );
      return;
    }

    if (!_coversWholeStay(currentDrafts)) {
      ScaffoldMessenger.of(context).showSnackBar(
        const SnackBar(
          content: Text(
            'La réservation ne couvre pas tout le séjour avec les chambres choisies. Ajoute une chambre libre pour compléter.',
          ),
          backgroundColor: Colors.orange,
        ),
      );
      return;
    }

    final roomSegments = currentDrafts
        .map(
          (draft) => {
            'room_id': draft.roomId,
            'segment_start_date': draft.startDate
                .toIso8601String()
                .split('T')
                .first,
            'segment_end_date': draft.endDate
                .toIso8601String()
                .split('T')
                .first,
            'segment_extra_beds': draft.extraBeds,
            'segment_extra_mattresses': draft.extraMattresses,
          },
        )
        .toList();
    final roomIds = roomSegments
        .map((segment) => _asInt(segment['room_id']))
        .toSet()
        .toList();

    if (_clientName().isEmpty || roomSegments.isEmpty) {
      ScaffoldMessenger.of(context).showSnackBar(
        const SnackBar(
          content: Text(
            'Veuillez remplir le nom et choisir au moins une chambre.',
          ),
        ),
      );
      return;
    }

    if (effectivePhone.isEmpty) {
      ScaffoldMessenger.of(context).showSnackBar(
        const SnackBar(
          content: Text('Veuillez renseigner un numéro de téléphone.'),
          backgroundColor: Colors.orange,
        ),
      );
      return;
    }

    setState(() {
      _loadingRooms = true;
      _savingBooking = true;
    });
    try {
      final response = await http
          .post(
            Uri.parse('$baseUrl/api/bookings'),
            headers: {'Content-Type': 'application/json'},
            body: json.encode({
              'client_name': _clientName(),
              'customer_phone': effectivePhone,
              'customer_email': effectiveEmail,
              'phone_number': effectivePhone,
              'organization_name': _isOrganizationBooking
                  ? _clientName()
                  : null,
              'organization_phone': _isOrganizationBooking
                  ? _organizationContactPhoneController.text.trim()
                  : null,
              'organization_contact_name': _isOrganizationBooking
                  ? _organizationContactNameController.text.trim()
                  : null,
              'organization_contact_phone': _isOrganizationBooking
                  ? effectivePhone
                  : null,
              'organization_contact_email': _isOrganizationBooking
                  ? _emailController.text.trim()
                  : null,
              'organization_email': _isOrganizationBooking
                  ? _organizationEmailController.text.trim()
                  : null,
              'organization_billing_address': _isOrganizationBooking
                  ? _organizationBillingAddressController.text.trim()
                  : null,
              'organization_nif': _isOrganizationBooking
                  ? _organizationNifController.text.trim()
                  : null,
              'organization_stat': _isOrganizationBooking
                  ? _organizationStatController.text.trim()
                  : null,
              'check_in': _checkIn.toIso8601String().substring(0, 10),
              'check_out': _checkOut.toIso8601String().substring(0, 10),
              'room_ids': roomIds,
              'room_segments': roomSegments,
              'room_prices': roomIds.map((id) {
                final room = _roomById(id);
                return {
                  'id': id,
                  'price': room != null ? _getSuggestedPrice(room) : 0,
                };
              }).toList(),
              'extra_beds': roomSegments.fold<int>(
                0,
                (total, segment) =>
                    total + _asInt(segment['segment_extra_beds']),
              ),
              'extra_mattresses': roomSegments.fold<int>(
                0,
                (total, segment) =>
                    total + _asInt(segment['segment_extra_mattresses']),
              ),
              'source': _isBookingCom
                  ? 'Booking'
                  : (effectivePhone.isNotEmpty ? 'Appel' : 'Mail'),
              'receptionist_name': widget.userName,
            }),
          )
          .timeout(const Duration(seconds: 30));

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
          if (response.statusCode == 429) {
            ScaffoldMessenger.of(context).showSnackBar(
              const SnackBar(
                content: Text(
                  'Le serveur est occupé. Attends quelques secondes puis réessaie.',
                ),
                backgroundColor: Colors.orange,
              ),
            );
            return;
          }
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
        final message = e.toString().contains('TimeoutException')
            ? 'La création a pris trop de temps. Vérifie si la réservation a bien été enregistrée avant de réessayer.'
            : 'Impossible de contacter le serveur.';
        ScaffoldMessenger.of(context).showSnackBar(
          SnackBar(content: Text(message), backgroundColor: Colors.red),
        );
      }
    } finally {
      if (mounted) {
        setState(() {
          _loadingRooms = false;
          _savingBooking = false;
        });
      }
    }
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

  Map<String, dynamic>? _roomById(int roomId) {
    for (final room in _allAvailableRooms) {
      if (_asInt(room['id']) == roomId) {
        return room is Map<String, dynamic>
            ? room
            : Map<String, dynamic>.from(room as Map);
      }
    }
    for (final room in _selectedRooms) {
      if (_asInt(room['id']) == roomId) {
        return room is Map<String, dynamic>
            ? room
            : Map<String, dynamic>.from(room as Map);
      }
    }
    return null;
  }

  List<Map<String, dynamic>> _roomAvailabilitySegments(dynamic room) {
    final raw = room['availability_segments'];
    if (raw is! Iterable) {
      return [
        {
          'segment_start_date': _checkIn.toIso8601String().substring(0, 10),
          'segment_end_date': _checkOut.toIso8601String().substring(0, 10),
        },
      ];
    }

    return raw.whereType<Map>().map((segment) {
      final parsed = Map<String, dynamic>.from(segment);
      final start = parsed['segment_start_date']?.toString();
      final end = parsed['segment_end_date']?.toString();
      return {
        'segment_start_date':
            start ?? _checkIn.toIso8601String().substring(0, 10),
        'segment_end_date': end ?? _checkOut.toIso8601String().substring(0, 10),
      };
    }).toList();
  }

  int _calculateTotalPrice() {
    return _calculateRoomPrice() + _calculateExtrasPrice();
  }

  int _calculateRoomNightPrice() {
    final drafts = _currentSegmentDrafts();
    if (drafts.isEmpty) return 0;
    final firstNight = _dateOnly(_checkIn);
    return drafts.fold<int>(0, (total, draft) {
      final coversFirstNight =
          !_dateOnly(draft.startDate).isAfter(firstNight) &&
          firstNight.isBefore(_dateOnly(draft.endDate));
      return coversFirstNight ? total + _segmentRoomNightPrice(draft) : total;
    });
  }

  int _calculateRoomPrice() {
    final drafts = _currentSegmentDrafts();
    if (drafts.isEmpty) return 0;
    final nights = _stayNightsList();
    return nights.fold<int>(0, (total, night) {
      final nightTotal = drafts.fold<int>(0, (nightSum, draft) {
        final coversNight =
            !_dateOnly(draft.startDate).isAfter(night) &&
            night.isBefore(_dateOnly(draft.endDate));
        return coversNight
            ? nightSum + _segmentRoomNightPrice(draft)
            : nightSum;
      });
      return total + nightTotal;
    });
  }

  int _calculateExtrasNightPrice() {
    final drafts = _currentSegmentDrafts();
    if (drafts.isEmpty) return 0;
    final firstNight = _dateOnly(_checkIn);
    return drafts.fold<int>(0, (total, draft) {
      final coversFirstNight =
          !_dateOnly(draft.startDate).isAfter(firstNight) &&
          firstNight.isBefore(_dateOnly(draft.endDate));
      return coversFirstNight ? total + _segmentExtrasNightPrice(draft) : total;
    });
  }

  int _calculateExtrasPrice() {
    final drafts = _currentSegmentDrafts();
    if (drafts.isEmpty) return 0;
    final nights = _stayNightsList();
    return nights.fold<int>(0, (total, night) {
      final nightTotal = drafts.fold<int>(0, (nightSum, draft) {
        final coversNight =
            !_dateOnly(draft.startDate).isAfter(night) &&
            night.isBefore(_dateOnly(draft.endDate));
        return coversNight
            ? nightSum + _segmentExtrasNightPrice(draft)
            : nightSum;
      });
      return total + nightTotal;
    });
  }

  bool _matchesRoomSearch(dynamic room) {
    final query = _roomSearchQuery.trim().toLowerCase();
    if (query.isEmpty) return true;

    final roomNumber = (room['room_number'] ?? '').toString().toLowerCase();
    final type = (room['type'] ?? '').toString().toLowerCase();
    final model = (room['model'] ?? '').toString().toLowerCase();
    return roomNumber.contains(query) ||
        type.contains(query) ||
        model.contains(query);
  }

  Widget _buildSegmentDraftCard(_RoomSegmentDraft draft) {
    final compatibleRooms = _compatibleRoomsForDraft(draft);
    final currentRoom = _roomById(draft.roomId);
    final currentRoomIncluded =
        currentRoom != null &&
        compatibleRooms.any((room) => _asInt(room['id']) == draft.roomId);
    final roomOptions = [
      if (currentRoom != null && !currentRoomIncluded) currentRoom,
      ...compatibleRooms,
    ];
    final uniqueRoomOptions = <int, Map<String, dynamic>>{};
    for (final room in roomOptions) {
      uniqueRoomOptions[_asInt(room['id'])] = room;
    }
    final displayRooms = uniqueRoomOptions.values.toList();
    final roomCountLabel = displayRooms.length == 1
        ? '1 chambre possible'
        : '${displayRooms.length} chambres possibles';
    final nights = _segmentNightCount(draft);
    final roomPrice = _segmentRoomNightPrice(draft);
    final extrasNightPrice = _segmentExtrasNightPrice(draft);
    final segmentTotal = (roomPrice + extrasNightPrice) * nights;

    return Card(
      elevation: 0,
      margin: EdgeInsets.zero,
      shape: RoundedRectangleBorder(
        borderRadius: BorderRadius.circular(12),
        side: BorderSide(color: _border),
      ),
      child: Padding(
        padding: const EdgeInsets.all(14),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Text(
              draft.roomLabel,
              style: const TextStyle(fontWeight: FontWeight.w900, color: _ink),
            ),
            const SizedBox(height: 4),
            Text(
              '$roomCountLabel • $nights nuit${nights > 1 ? 's' : ''}',
              style: const TextStyle(
                fontSize: 12,
                fontWeight: FontWeight.w700,
                color: _muted,
              ),
            ),
            const SizedBox(height: 10),
            DropdownButtonFormField<int>(
              initialValue: draft.roomId,
              decoration: const InputDecoration(
                labelText: 'Chambre du segment',
                border: OutlineInputBorder(),
                isDense: true,
              ),
              items: displayRooms.map((room) {
                final roomId = _asInt(room['id']);
                final available = _roomCoversRange(
                  room,
                  draft.startDate,
                  draft.endDate,
                );
                final label = _roomLabel(room);
                return DropdownMenuItem<int>(
                  value: roomId,
                  child: Text(
                    available ? label : '$label (indisponible)',
                    style: TextStyle(color: available ? _ink : _rose),
                  ),
                );
              }).toList(),
              onChanged: displayRooms.isEmpty
                  ? null
                  : (value) {
                      if (value != null) _updateDraftRoom(draft, value);
                    },
            ),
            const SizedBox(height: 10),
            ListTile(
              dense: true,
              contentPadding: EdgeInsets.zero,
              leading: const Icon(Icons.login),
              title: Text('Début: ${_formatShortDate(draft.startDate)}'),
              trailing: const Icon(Icons.calendar_today_outlined),
              onTap: () async {
                final minDate = _dateOnly(_checkIn);
                final picked = await showDatePicker(
                  context: context,
                  initialDate: draft.startDate.isBefore(minDate)
                      ? minDate
                      : draft.startDate,
                  firstDate: minDate,
                  lastDate: _dateOnly(_checkOut),
                );
                if (picked == null) return;
                _updateDraftStartDate(draft, picked);
              },
            ),
            ListTile(
              dense: true,
              contentPadding: EdgeInsets.zero,
              leading: const Icon(Icons.logout),
              title: Text('Fin: ${_formatShortDate(draft.endDate)}'),
              trailing: const Icon(Icons.calendar_today_outlined),
              onTap: () async {
                final minDate = draft.startDate.add(const Duration(days: 1));
                final picked = await showDatePicker(
                  context: context,
                  initialDate: draft.endDate.isAfter(minDate)
                      ? draft.endDate
                      : minDate,
                  firstDate: minDate,
                  lastDate: _dateOnly(_checkOut),
                );
                if (picked == null) return;
                _updateDraftEndDate(draft, picked);
              },
            ),
            if (!currentRoomIncluded && currentRoom != null) ...[
              const SizedBox(height: 4),
              const Text(
                'La chambre actuelle ne couvre plus ce segment. Choisis une chambre compatible.',
                style: TextStyle(color: _rose, fontWeight: FontWeight.w700),
              ),
            ],
            const SizedBox(height: 10),
            _QuantitySelector(
              icon: Icons.bed_outlined,
              label: 'Lit supplémentaire',
              unitPrice: 50000,
              stayNights: nights,
              value: draft.extraBeds,
              maxValue: _remainingExtraBeds,
              onChanged: (value) => setState(() => draft.extraBeds = value),
            ),
            const SizedBox(height: 8),
            _QuantitySelector(
              icon: Icons.airline_seat_individual_suite_outlined,
              label: 'Matelas supplémentaire',
              unitPrice: 30000,
              stayNights: nights,
              value: draft.extraMattresses,
              maxValue: _remainingExtraMattresses,
              onChanged: (value) =>
                  setState(() => draft.extraMattresses = value),
            ),
            const SizedBox(height: 10),
            Text(
              'Chambre : ${formatPrice(roomPrice)} Ar / nuit • Extras : ${formatPrice(extrasNightPrice)} Ar / nuit • Segment : ${formatPrice(segmentTotal)} Ar',
              style: const TextStyle(
                fontSize: 12,
                fontWeight: FontWeight.w700,
                color: _muted,
              ),
            ),
          ],
        ),
      ),
    );
  }

  bool _hasMeaningfulRoomSplit(List<_RoomSegmentDraft> drafts) {
    if (drafts.isEmpty) return false;
    return drafts.any(
      (draft) =>
          _dateOnly(draft.startDate) != _dateOnly(_checkIn) ||
          _dateOnly(draft.endDate) != _dateOnly(_checkOut),
    );
  }

  @override
  Widget build(BuildContext context) {
    final plannedSegments = _currentSegmentDrafts();
    final showEditableSplit = _hasMeaningfulRoomSplit(plannedSegments);
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
    filteredRooms = filteredRooms
        .where(_shouldDisplayRoom)
        .where(_matchesRoomSearch)
        .toList();

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
                  if (_isOrganizationBooking)
                    OrganizationAutocompleteField(
                      controller: _nameController,
                      labelText: 'Nom de l’organisme',
                      prefixIcon: Icons.apartment_outlined,
                      keyboardType: TextInputType.name,
                      textInputAction: TextInputAction.next,
                      onSelected: _applyOrganization,
                    )
                  else
                    ClientAutocompleteField(
                      controller: _nameController,
                      labelText: _reservationNameLabel,
                      prefixIcon: Icons.person_outline,
                      keyboardType: TextInputType.name,
                      textInputAction: TextInputAction.next,
                      valueBuilder: (client) => client.displayName,
                      onSelected: _applyClient,
                      showLoyalty: widget.role != 'receptionist',
                    ),
                  const SizedBox(height: 12),
                  if (_isOrganizationBooking) ...[
                    const SizedBox(height: 12),
                    TextField(
                      controller: _organizationContactNameController,
                      decoration: const InputDecoration(
                        labelText: 'Contact organisme',
                        prefixIcon: Icon(Icons.badge_outlined),
                        border: OutlineInputBorder(),
                      ),
                    ),
                    const SizedBox(height: 12),
                    TextField(
                      controller: _organizationEmailController,
                      keyboardType: TextInputType.emailAddress,
                      decoration: const InputDecoration(
                        labelText: 'Email de l’organisme',
                        prefixIcon: Icon(Icons.email_outlined),
                        border: OutlineInputBorder(),
                      ),
                    ),
                    const SizedBox(height: 12),
                    TextField(
                      controller: _organizationContactPhoneController,
                      keyboardType: TextInputType.phone,
                      decoration: const InputDecoration(
                        labelText: 'Téléphone du siège',
                        prefixIcon: Icon(Icons.business_outlined),
                        border: OutlineInputBorder(),
                      ),
                    ),
                    const SizedBox(height: 12),
                    TextField(
                      controller: _organizationBillingAddressController,
                      decoration: const InputDecoration(
                        labelText: 'Adresse de facturation',
                        prefixIcon: Icon(Icons.location_on_outlined),
                        border: OutlineInputBorder(),
                      ),
                    ),
                    const SizedBox(height: 12),
                    TextField(
                      controller: _organizationNifController,
                      decoration: const InputDecoration(
                        labelText: 'NIF',
                        prefixIcon: Icon(Icons.receipt_long_outlined),
                        border: OutlineInputBorder(),
                      ),
                    ),
                    const SizedBox(height: 12),
                    TextField(
                      controller: _organizationStatController,
                      decoration: const InputDecoration(
                        labelText: 'STAT',
                        prefixIcon: Icon(Icons.account_balance_outlined),
                        border: OutlineInputBorder(),
                      ),
                    ),
                    const SizedBox(height: 12),
                  ],
                  if (_isOrganizationBooking)
                    TextField(
                      controller: _phoneController,
                      keyboardType: TextInputType.phone,
                      decoration: const InputDecoration(
                        labelText: 'Numéro de la personne à contacter',
                        prefixIcon: Icon(Icons.phone),
                        border: OutlineInputBorder(),
                      ),
                    )
                  else
                    ClientAutocompleteField(
                      controller: _phoneController,
                      labelText: 'Téléphone',
                      prefixIcon: Icons.phone,
                      keyboardType: TextInputType.phone,
                      textInputAction: TextInputAction.next,
                      valueBuilder: (client) =>
                          client.phoneNumber?.trim() ?? '',
                      onSelected: _applyClient,
                      showLoyalty: widget.role != 'receptionist',
                    ),
                  const SizedBox(height: 12),
                  TextField(
                    controller: _emailController,
                    keyboardType: TextInputType.emailAddress,
                    decoration: InputDecoration(
                      labelText: _isOrganizationBooking
                          ? 'Email contact'
                          : 'Email / Autre contact (si pas de téléphone)',
                      prefixIcon: const Icon(Icons.contact_mail),
                      border: const OutlineInputBorder(),
                    ),
                  ),
                  const SizedBox(height: 12),
                  if (_selectedClient != null && widget.role != 'receptionist')
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
                  if (_selectedClient != null && widget.role != 'receptionist')
                    const SizedBox(height: 12),
                  if (!_isOrganizationBooking)
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
                          _selectedRooms.clear();
                          _segmentDrafts.clear();
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
                    child: Column(
                      crossAxisAlignment: CrossAxisAlignment.start,
                      children: [
                        _SummaryLine(
                          label: 'Prix par nuit chambres',
                          value:
                              '${formatPrice(_calculateRoomNightPrice())} Ar',
                        ),
                        const SizedBox(height: 6),
                        _SummaryLine(
                          label: 'Prix total chambre',
                          value: '${formatPrice(_calculateRoomPrice())} Ar',
                        ),
                        const SizedBox(height: 6),
                        _SummaryLine(
                          label: 'Prix par nuit option',
                          value:
                              '${formatPrice(_calculateExtrasNightPrice())} Ar',
                        ),
                        const SizedBox(height: 6),
                        _SummaryLine(
                          label: 'Prix total option',
                          value: '${formatPrice(_calculateExtrasPrice())} Ar',
                        ),
                        const Divider(height: 20),
                        _SummaryLine(
                          label: 'Prix total',
                          value: '${formatPrice(_calculateTotalPrice())} Ar',
                          emphasized: true,
                        ),
                        if (showEditableSplit) ...[
                          const Divider(height: 20),
                          const Text(
                            'Découpage proposé modifiable',
                            style: TextStyle(
                              fontSize: 12,
                              fontWeight: FontWeight.w900,
                              color: _primaryDark,
                            ),
                          ),
                          const SizedBox(height: 8),
                          ...plannedSegments.map(
                            (draft) => Padding(
                              padding: const EdgeInsets.only(bottom: 12),
                              child: _buildSegmentDraftCard(draft),
                            ),
                          ),
                        ] else if (_selectedRooms.isEmpty) ...[
                          const Divider(height: 20),
                          const Text(
                            'Sélectionne au moins une chambre pour générer un découpage modifiable.',
                            style: TextStyle(
                              fontSize: 12,
                              fontWeight: FontWeight.w700,
                              color: _muted,
                            ),
                          ),
                        ],
                      ],
                    ),
                  ),
                  const SizedBox(height: 20),
                  SizedBox(
                    width: double.infinity,
                    child: ElevatedButton(
                      onPressed: _savingBooking ? null : _saveBooking,
                      child: _savingBooking
                          ? const SizedBox(
                              width: 18,
                              height: 18,
                              child: CircularProgressIndicator(strokeWidth: 2),
                            )
                          : const Text('Enregistrer la réservation'),
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
                  TextField(
                    onChanged: (value) =>
                        setState(() => _roomSearchQuery = value),
                    decoration: const InputDecoration(
                      labelText: 'Rechercher une chambre',
                      hintText: 'Numéro, type ou modèle',
                      prefixIcon: Icon(Icons.search),
                    ),
                  ),
                  const SizedBox(height: 10),
                  Row(
                    children: [
                      Expanded(
                        child: OutlinedButton.icon(
                          onPressed: () =>
                              setState(() => _showRoomsNeedingSplit = false),
                          icon: Icon(
                            Icons.event_available_outlined,
                            color: _showRoomsNeedingSplit
                                ? _muted
                                : _primaryDark,
                          ),
                          label: const Text('Séjour complet'),
                          style: OutlinedButton.styleFrom(
                            foregroundColor: _showRoomsNeedingSplit
                                ? _muted
                                : _primaryDark,
                            side: BorderSide(
                              color: _showRoomsNeedingSplit
                                  ? _border
                                  : _primary,
                            ),
                            backgroundColor: _showRoomsNeedingSplit
                                ? Colors.white
                                : _primary.withValues(alpha: 0.08),
                          ),
                        ),
                      ),
                      const SizedBox(width: 8),
                      Expanded(
                        child: OutlinedButton.icon(
                          onPressed: () =>
                              setState(() => _showRoomsNeedingSplit = true),
                          icon: Icon(
                            Icons.call_split_outlined,
                            color: _showRoomsNeedingSplit
                                ? _primaryDark
                                : _muted,
                          ),
                          label: const Text('Avec découpage'),
                          style: OutlinedButton.styleFrom(
                            foregroundColor: _showRoomsNeedingSplit
                                ? _primaryDark
                                : _muted,
                            side: BorderSide(
                              color: _showRoomsNeedingSplit
                                  ? _primary
                                  : _border,
                            ),
                            backgroundColor: _showRoomsNeedingSplit
                                ? _primary.withValues(alpha: 0.08)
                                : Colors.white,
                          ),
                        ),
                      ),
                    ],
                  ),
                  Padding(
                    padding: const EdgeInsets.only(top: 4),
                    child: Text(
                      _showRoomsNeedingSplit
                          ? 'Affiche aussi les chambres libres seulement une partie du séjour.'
                          : 'Affiche uniquement les chambres libres sur tout le séjour.',
                      style: const TextStyle(
                        color: _muted,
                        fontSize: 12,
                        fontWeight: FontWeight.w700,
                      ),
                    ),
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
                          final roomId = _asInt(room['id']);
                          final isSelected = _selectedRooms.any(
                            (r) => _asInt(r['id']) == roomId,
                          );
                          String roomLabel =
                              'Chambre ${room['room_number']} — ${room['type']} (${room['model']})';
                          final fixedPrice = _getFixedPrice(room);
                          final isFixedPrice = room['is_fixed_price'] == true;
                          final aiPrice = _isBookingCom
                              ? 162500
                              : _getSuggestedPrice(room);
                          final segments = _roomAvailabilitySegments(room);
                          final hasAvailability = segments.isNotEmpty;
                          final availabilityLabel = segments.isEmpty
                              ? 'Indisponible sur la période'
                              : (room['is_fully_available'] == true
                                    ? 'Disponible sur tout le séjour'
                                    : 'Libre: ${segments.map((segment) {
                                        final start = DateTime.tryParse(segment['segment_start_date']?.toString() ?? '');
                                        final end = DateTime.tryParse(segment['segment_end_date']?.toString() ?? '');
                                        if (start == null || end == null) {
                                          return '';
                                        }
                                        return '${_formatShortDate(start)} → ${_formatShortDate(end)}';
                                      }).where((label) => label.isNotEmpty).join(' • ')}');

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
                                    availabilityLabel,
                                    style: TextStyle(
                                      color: hasAvailability
                                          ? _primaryDark
                                          : _rose,
                                      fontWeight: FontWeight.w800,
                                    ),
                                  ),
                                  const SizedBox(height: 4),
                                  Text(
                                    'Prix fixe : ${formatPrice(fixedPrice)} Ar / nuit',
                                    style: const TextStyle(
                                      color: _primaryDark,
                                      fontWeight: FontWeight.w900,
                                    ),
                                  ),
                                  if (!_isOrganizationBooking)
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
                            enabled: hasAvailability,
                            onChanged: (val) {
                              setState(() {
                                if (val == true) {
                                  if (_selectedRooms.every(
                                    (r) => _asInt(r['id']) != roomId,
                                  )) {
                                    _selectedRooms.add(room);
                                  }
                                } else {
                                  _selectedRooms.removeWhere(
                                    (r) => _asInt(r['id']) == roomId,
                                  );
                                }
                                _rebuildSegmentDraftsFromSelection();
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
