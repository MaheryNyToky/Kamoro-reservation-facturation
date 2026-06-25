# hestiapredict

Backend Laravel du projet HestiaPredict.

Il gère :

- l'authentification du staff ;
- les disponibilités et réservations ;
- les check-in, folios, paiements et PDF ;
- les acomptes enregistrés avant ou après le check-in ;
- la génération de factures PDF compactes, pensées pour tenir sur une page dans le cas standard ;
- l'orchestration du moteur IA FastAPI.

## Référence

La documentation complète et les instructions de lancement sont dans le README racine :

```text
../README.md
```

Commandes utiles :

```bash
composer install
php artisan test
php artisan serve --host=127.0.0.1 --port=8000
```
