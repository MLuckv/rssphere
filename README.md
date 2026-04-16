# RSSphere — Lecteur RSS local

## Prérequis
- PHP 7.4+ avec `allow_url_fopen = On` dans php.ini

## Lancement rapide

```bash
cd rssphere
php -S localhost:8080
```

Puis ouvrez http://localhost:8080 dans votre navigateur.

## Structure
```
rssphere/
├── index.php          # Interface principale (protegee par connexion)
├── auth.php           # Page connexion / inscription
├── logout.php         # Deconnexion explicite
├── auth_utils.php     # Gestion utilisateurs, sessions, cookies persistants
├── api/index.php      # API PHP (fetch RSS, recherche, BDD)
├── assets/
│   ├── css/style.css  # Styles de l'application
│   ├── css/auth.css   # Styles de la page d'authentification
│   └── js/app.js      # Logique frontend
└── data/
	├── users.json       # Comptes locaux (email + mot de passe hashé)
	├── db.json          # Ancienne base globale (migration douce)
	└── user_dbs/        # Base JSON par utilisateur (flux + sauvegardes)
```

## Fonctionnalités
- Comptes locaux (inscription / connexion / deconnexion)
- Validation email a l'inscription
- Mot de passe securise avec `password_hash` (salt aleatoire integre)
- Session persistante (reste connecte jusqu'a la deconnexion)
- Donnees RSS isolees par compte (un JSON par utilisateur)
- Lecture de flux RSS en temps réel via PHP
- Recherche intégrée parmi 35+ flux populaires
- Ajout manuel par URL RSS
- Sauvegarde d'articles
- Marquage lu/non-lu
- Filtrage par catégorie / flux
- Panneau de lecture intégré
- Actualisation manuelle

## Note
Les fichiers de `data/` doivent etre accessibles en ecriture par PHP (notamment `users.json` et `user_dbs/`) :
```bash
chmod 664 data/users.json
chmod -R 775 data/user_dbs
```
