<?php
/**
 * Catalogue de flux proposes dans l'onglet "Decouvrir".
 * Aucun de ces flux n'est abonne par defaut : ils servent de terrain
 * de recherche/apercu, l'abonnement reste un geste explicite.
 */

function feedCatalog() {
    return [
        // ── Tech ────────────────────────────────────────────────────────────
        ['name'=>'Hacker News','url'=>'https://news.ycombinator.com/rss','cat'=>'Tech','lang'=>'en','desc'=>'Actualités tech & startups'],
        ['name'=>'The Verge','url'=>'https://www.theverge.com/rss/index.xml','cat'=>'Tech','lang'=>'en','desc'=>'Tech & culture numérique'],
        ['name'=>'Ars Technica','url'=>'https://feeds.arstechnica.com/arstechnica/index','cat'=>'Tech','lang'=>'en','desc'=>'Science, tech & culture'],
        ['name'=>'Wired','url'=>'https://www.wired.com/feed/rss','cat'=>'Tech','lang'=>'en','desc'=>'Tech & société'],
        ['name'=>'Numerama','url'=>'https://www.numerama.com/feed/','cat'=>'Tech','lang'=>'fr','desc'=>'Tech française'],
        ['name'=>'01net','url'=>'https://www.01net.com/rss/news/','cat'=>'Tech','lang'=>'fr','desc'=>'Actualité numérique FR'],
        ['name'=>'Next INpact','url'=>'https://next.ink/feed/','cat'=>'Tech','lang'=>'fr','desc'=>'Numérique, droit & libertés'],
        ['name'=>'Le Journal du Net','url'=>'https://www.journaldunet.com/rss/','cat'=>'Tech','lang'=>'fr','desc'=>'Business & numérique'],
        ['name'=>'MIT Technology Review','url'=>'https://www.technologyreview.com/feed/','cat'=>'Tech','lang'=>'en','desc'=>'Innovation & IA'],
        ['name'=>'TechCrunch','url'=>'https://techcrunch.com/feed/','cat'=>'Tech','lang'=>'en','desc'=>'Startups & tech US'],
        ['name'=>'Hacker Noon','url'=>'https://hackernoon.com/feed','cat'=>'Tech','lang'=>'en','desc'=>'Développement & ingénierie'],
        ['name'=>'GitHub Blog','url'=>'https://github.blog/feed/','cat'=>'Tech','lang'=>'en','desc'=>'Outils & écosystème dev'],
        ['name'=>'Stack Overflow Blog','url'=>'https://stackoverflow.blog/feed/','cat'=>'Tech','lang'=>'en','desc'=>'Métier de développeur'],
        ['name'=>'Google AI Blog','url'=>'https://blog.google/technology/ai/rss/','cat'=>'Tech','lang'=>'en','desc'=>'Recherche IA Google'],
        ['name'=>'Anthropic','url'=>'https://www.anthropic.com/rss.xml','cat'=>'Tech','lang'=>'en','desc'=>'Recherche & produits IA'],
        ['name'=>'OpenAI','url'=>'https://openai.com/news/rss.xml','cat'=>'Tech','lang'=>'en','desc'=>'Annonces & recherche IA'],
        ['name'=>'Unity Blog','url'=>'https://blog.unity.com/feed','cat'=>'Tech','lang'=>'en','desc'=>'Moteur de jeu Unity'],

        // ── Actualités ──────────────────────────────────────────────────────
        ['name'=>'Le Monde','url'=>'https://www.lemonde.fr/rss/une.xml','cat'=>'Actualités','lang'=>'fr','desc'=>'Journal français de référence'],
        ['name'=>'Le Figaro','url'=>'https://www.lefigaro.fr/rss/figaro_actualites.xml','cat'=>'Actualités','lang'=>'fr','desc'=>'Actualité française'],
        ['name'=>'Libération','url'=>'https://www.liberation.fr/arc/outboundfeeds/rss-all/?outputType=xml','cat'=>'Actualités','lang'=>'fr','desc'=>'Journal français'],
        ['name'=>'France Info','url'=>'https://www.francetvinfo.fr/titres.rss','cat'=>'Actualités','lang'=>'fr','desc'=>'Info en continu FR'],
        ['name'=>'France 24','url'=>'https://www.france24.com/fr/rss','cat'=>'Actualités','lang'=>'fr','desc'=>'International en français'],
        ['name'=>'RFI','url'=>'https://www.rfi.fr/fr/rss','cat'=>'Actualités','lang'=>'fr','desc'=>'Radio internationale'],
        ['name'=>'Mediapart','url'=>'https://www.mediapart.fr/articles/feed','cat'=>'Actualités','lang'=>'fr','desc'=>'Enquêtes & investigation'],
        ['name'=>'Courrier International','url'=>'https://www.courrierinternational.com/feed/all/rss.xml','cat'=>'Actualités','lang'=>'fr','desc'=>'Presse étrangère traduite'],
        ['name'=>'BBC News','url'=>'http://feeds.bbci.co.uk/news/rss.xml','cat'=>'Actualités','lang'=>'en','desc'=>'International anglais'],
        ['name'=>'The Guardian','url'=>'https://www.theguardian.com/international/rss','cat'=>'Actualités','lang'=>'en','desc'=>'Quotidien britannique'],
        ['name'=>'Al Jazeera','url'=>'https://www.aljazeera.com/xml/rss/all.xml','cat'=>'Actualités','lang'=>'en','desc'=>'Info internationale'],
        ['name'=>'NPR','url'=>'https://feeds.npr.org/1001/rss.xml','cat'=>'Actualités','lang'=>'en','desc'=>'Radio publique US'],

        // ── Science ─────────────────────────────────────────────────────────
        ['name'=>'NASA Breaking News','url'=>'https://www.nasa.gov/rss/dyn/breaking_news.rss','cat'=>'Science','lang'=>'en','desc'=>'Agence spatiale US'],
        ['name'=>'ESA','url'=>'https://www.esa.int/rssfeed/Our_Activities/Space_News','cat'=>'Science','lang'=>'en','desc'=>'Agence spatiale européenne'],
        ['name'=>'Sciences et Avenir','url'=>'https://www.sciencesetavenir.fr/rss.xml','cat'=>'Science','lang'=>'fr','desc'=>'Vulgarisation scientifique'],
        ['name'=>'Pour la Science','url'=>'https://www.pourlascience.fr/rss.xml','cat'=>'Science','lang'=>'fr','desc'=>'Science magazine FR'],
        ['name'=>'Futura Sciences','url'=>'https://www.futura-sciences.com/rss/actualites.xml','cat'=>'Science','lang'=>'fr','desc'=>'Science & tech FR'],
        ['name'=>'CNRS Le Journal','url'=>'https://lejournal.cnrs.fr/rss','cat'=>'Science','lang'=>'fr','desc'=>'Recherche publique française'],
        ['name'=>'Nature','url'=>'https://www.nature.com/nature.rss','cat'=>'Science','lang'=>'en','desc'=>'Revue scientifique mondiale'],
        ['name'=>'Science Daily','url'=>'https://www.sciencedaily.com/rss/all.xml','cat'=>'Science','lang'=>'en','desc'=>'Recherche scientifique'],
        ['name'=>'Quanta Magazine','url'=>'https://api.quantamagazine.org/feed/','cat'=>'Science','lang'=>'en','desc'=>'Maths, physique, informatique'],
        ['name'=>'Phys.org','url'=>'https://phys.org/rss-feed/','cat'=>'Science','lang'=>'en','desc'=>'Actualité de la recherche'],

        // ── Gaming ──────────────────────────────────────────────────────────
        ['name'=>'Eurogamer','url'=>'https://www.eurogamer.net/feed','cat'=>'Gaming','lang'=>'en','desc'=>'Jeux vidéo Europe'],
        ['name'=>'Jeuxvideo.com','url'=>'https://www.jeuxvideo.com/rss/rss-news.xml','cat'=>'Gaming','lang'=>'fr','desc'=>'Jeux vidéo FR'],
        ['name'=>'Gamekult','url'=>'https://www.gamekult.com/feed.xml','cat'=>'Gaming','lang'=>'fr','desc'=>'Gaming français'],
        ['name'=>'IGN France','url'=>'https://fr.ign.com/feed.xml','cat'=>'Gaming','lang'=>'fr','desc'=>'Gaming & pop culture'],
        ['name'=>'Rock Paper Shotgun','url'=>'https://www.rockpapershotgun.com/feed','cat'=>'Gaming','lang'=>'en','desc'=>'Jeux PC & indés'],
        ['name'=>'Game Developer','url'=>'https://www.gamedeveloper.com/rss.xml','cat'=>'Gaming','lang'=>'en','desc'=>'Game design & production'],

        // ── Culture ─────────────────────────────────────────────────────────
        ['name'=>'Télérama','url'=>'https://www.telerama.fr/rss/','cat'=>'Culture','lang'=>'fr','desc'=>'Cinéma, séries, musique'],
        ['name'=>'France Culture','url'=>'https://www.radiofrance.fr/franceculture/rss','cat'=>'Culture','lang'=>'fr','desc'=>'Idées & savoirs'],
        ['name'=>'Konbini','url'=>'https://www.konbini.com/fr/feed/','cat'=>'Culture','lang'=>'fr','desc'=>'Pop culture FR'],
        ['name'=>'Pitchfork','url'=>'https://pitchfork.com/rss/news/','cat'=>'Culture','lang'=>'en','desc'=>'Critique musicale'],
        ['name'=>'The Paris Review','url'=>'https://www.theparisreview.org/blog/feed/','cat'=>'Culture','lang'=>'en','desc'=>'Littérature & entretiens'],
        ['name'=>'Aeon','url'=>'https://aeon.co/feed.rss','cat'=>'Culture','lang'=>'en','desc'=>'Essais, philosophie, histoire'],

        // ── Histoire ────────────────────────────────────────────────────────
        ['name'=>'Le Monde · Histoire','url'=>'https://www.lemonde.fr/histoire/rss_full.xml','cat'=>'Histoire','lang'=>'fr','desc'=>'Histoire, mémoire & historiens'],
        ['name'=>'Le Monde · Archéologie','url'=>'https://www.lemonde.fr/archeologie/rss_full.xml','cat'=>'Histoire','lang'=>'fr','desc'=>'Fouilles & découvertes'],
        ['name'=>'Histoire et Civilisations','url'=>'https://www.histoire-et-civilisations.com/une/rss.xml','cat'=>'Histoire','lang'=>'fr','desc'=>'Magazine d’histoire grand public'],
        ['name'=>'Histoire pour tous','url'=>'https://www.histoire-pour-tous.fr/index.php?format=feed&type=rss','cat'=>'Histoire','lang'=>'fr','desc'=>'Histoire de France et du monde'],
        ['name'=>'L’Histoire à la BnF','url'=>'https://histoirebnf.hypotheses.org/feed','cat'=>'Histoire','lang'=>'fr','desc'=>'Carnet des historiens de la BnF'],
        ['name'=>'Histoire Engagée','url'=>'https://histoireengagee.ca/feed/','cat'=>'Histoire','lang'=>'fr','desc'=>'Histoire & débats publics (Québec)'],
        ['name'=>'Smithsonian · History','url'=>'https://www.smithsonianmag.com/rss/history/','cat'=>'Histoire','lang'=>'en','desc'=>'Récits historiques du Smithsonian'],
        ['name'=>'HistoryExtra','url'=>'https://www.historyextra.com/feed/','cat'=>'Histoire','lang'=>'en','desc'=>'Le magazine d’histoire de la BBC'],
        ['name'=>'History Today','url'=>'https://www.historytoday.com/feed/rss.xml','cat'=>'Histoire','lang'=>'en','desc'=>'Revue d’histoire britannique'],
        ['name'=>'JSTOR Daily','url'=>'https://daily.jstor.org/feed/','cat'=>'Histoire','lang'=>'en','desc'=>'L’actualité éclairée par la recherche'],
        ['name'=>'World History Encyclopedia','url'=>'https://www.worldhistory.org/rss/','cat'=>'Histoire','lang'=>'en','desc'=>'Antiquité & civilisations'],
        ['name'=>'The History Blog','url'=>'http://www.thehistoryblog.com/feed','cat'=>'Histoire','lang'=>'en','desc'=>'Archéologie & objets d’histoire'],
        ['name'=>'Medieval Histories','url'=>'https://www.medievalhistories.com/feed/','cat'=>'Histoire','lang'=>'en','desc'=>'Le Moyen Âge au quotidien'],
        ['name'=>'The Public Domain Review','url'=>'https://publicdomainreview.org/rss.xml','cat'=>'Histoire','lang'=>'en','desc'=>'Curiosités des archives & du domaine public'],

        // ── Économie ────────────────────────────────────────────────────────
        ['name'=>'Les Échos','url'=>'https://syndication.lesechos.fr/rss/rss_la_une.xml','cat'=>'Économie','lang'=>'fr','desc'=>'Finance & business FR'],
        ['name'=>'BFM Business','url'=>'https://bfmbusiness.bfmtv.com/rss/info/flux-rss/flux-toutes-les-actualites/','cat'=>'Économie','lang'=>'fr','desc'=>'Économie française'],
        ['name'=>"L'Usine Nouvelle",'url'=>'https://www.usinenouvelle.com/rss/all','cat'=>'Économie','lang'=>'fr','desc'=>'Industrie & innovation'],
        ['name'=>'Alternatives Économiques','url'=>'https://www.alternatives-economiques.fr/rss.xml','cat'=>'Économie','lang'=>'fr','desc'=>'Analyse économique'],

        // ── Sport ───────────────────────────────────────────────────────────
        ['name'=>"L'Équipe",'url'=>'https://www.lequipe.fr/rss/actu_rss.xml','cat'=>'Sport','lang'=>'fr','desc'=>'Sports français'],
        ['name'=>'Eurosport','url'=>'https://www.eurosport.fr/rss.xml','cat'=>'Sport','lang'=>'fr','desc'=>'Sports Europe'],
        ['name'=>'BBC Sport','url'=>'https://feeds.bbci.co.uk/sport/rss.xml','cat'=>'Sport','lang'=>'en','desc'=>'Sport international'],
    ];
}

/** Categories du catalogue, dans un ordre stable pour l'UI. */
function catalogCategories() {
    $cats = [];
    foreach (feedCatalog() as $f) {
        $cats[$f['cat']] = ($cats[$f['cat']] ?? 0) + 1;
    }
    return $cats;
}
