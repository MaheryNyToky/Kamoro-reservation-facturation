# hestia_app

Application Flutter Web du projet HestiaPredict.

Elle sert d'interface de réception pour :

- les réservations ;
- les check-in ;
- les folios et paiements ;
- la consultation des disponibilités ;
- l'affichage des compteurs et listes métiers avec rafraîchissement plus fiable des statuts `en_attente` et des occupations confirmées ;
- la saisie d'acomptes avec protection contre les doubles envois pendant la validation ;
- l'accès aux fonctions de gestion selon le rôle.

## Lancer

Depuis la racine du dépôt, voir le README principal :

```text
../README.md
```

Commandes utiles :

```bash
flutter pub get
flutter test
flutter build web --dart-define=API_BASE_URL=http://127.0.0.1:8000
```
