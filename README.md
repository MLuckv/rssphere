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
├── index.php          # Interface principale
├── api/index.php      # API PHP (fetch RSS, recherche, BDD)
├── assets/
│   ├── css/style.css  # Styles
│   └── js/app.js      # Logique frontend
└── data/db.json       # Base de données JSON (flux + sauvegardés)
```

## Fonctionnalités
- Lecture de flux RSS en temps réel via PHP
- Recherche intégrée parmi 35+ flux populaires
- Ajout manuel par URL RSS
- Sauvegarde d'articles
- Marquage lu/non-lu
- Filtrage par catégorie / flux
- Panneau de lecture intégré
- Actualisation manuelle

## Note
Le fichier `data/db.json` doit être accessible en écriture par PHP :
```bash
chmod 664 data/db.json
```
