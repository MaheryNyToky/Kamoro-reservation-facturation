from __future__ import annotations

import shutil
import tempfile
from datetime import datetime, timezone
from pathlib import Path
from xml.sax.saxutils import escape
from zipfile import ZipFile, ZIP_DEFLATED


ROOT = Path(__file__).resolve().parent
TEMPLATE = ROOT / "Checklist.docx"
OUTPUT = ROOT / "Synthese_HestiaPredict.docx"


def pt(value: int | float) -> str:
    return str(int(round(float(value) * 2)))


def dxa_from_in(value: float) -> str:
    return str(int(round(value * 1440)))


def run_xml(text: str, *, size: int = 22, bold: bool = False, italic: bool = False, color: str = "000000") -> str:
    props = [
        '<w:rFonts w:ascii="Calibri" w:hAnsi="Calibri" w:eastAsia="Calibri" w:cs="Calibri"/>',
        f'<w:color w:val="{color}"/>',
        f'<w:sz w:val="{size * 2}"/>',
        f'<w:szCs w:val="{size * 2}"/>',
    ]
    if bold:
        props.append("<w:b/>")
        props.append("<w:bCs/>")
    if italic:
        props.append("<w:i/>")
        props.append("<w:iCs/>")
    return (
        "<w:r>"
        "<w:rPr>" + "".join(props) + "</w:rPr>"
        f"<w:t xml:space=\"preserve\">{escape(text)}</w:t>"
        "</w:r>"
    )


def para_xml(
    runs: list[str],
    *,
    before_pt: float = 0,
    after_pt: float = 6,
    line: float = 1.1,
    align: str = "left",
    space_before_keep: bool = False,
) -> str:
    line_twips = int(round(line * 240))
    align_xml = f"<w:jc w:val=\"{align}\"/>" if align != "left" else ""
    keep = "<w:keepNext/>" if space_before_keep else ""
    return (
        "<w:p>"
        "<w:pPr>"
        f"<w:spacing w:before=\"{pt(before_pt)}\" w:after=\"{pt(after_pt)}\" w:line=\"{line_twips}\" w:lineRule=\"auto\"/>"
        f"{align_xml}"
        f"{keep}"
        "</w:pPr>"
        + "".join(runs)
        + "</w:p>"
    )


def heading_xml(text: str, level: int = 1) -> str:
    if level == 1:
        size = 18
        before = 18
        after = 6
        color = "2E74B5"
    else:
        size = 13
        before = 10
        after = 4
        color = "2E74B5"
    return (
        "<w:p>"
        "<w:pPr>"
        f"<w:spacing w:before=\"{pt(before)}\" w:after=\"{pt(after)}\" w:line=\"240\" w:lineRule=\"auto\"/>"
        "<w:keepNext/>"
        "</w:pPr>"
        + run_xml(text, size=size, bold=True, color=color)
        + "</w:p>"
    )


def title_xml(text: str) -> str:
    return para_xml(
        [run_xml(text, size=24, bold=True, color="0B2545")],
        before_pt=0,
        after_pt=4,
        line=1.0,
    )


def subtitle_xml(text: str) -> str:
    return para_xml(
        [run_xml(text, size=11, italic=True, color="666666")],
        before_pt=0,
        after_pt=10,
        line=1.05,
    )


def body_paragraph(text: str) -> str:
    return para_xml([run_xml(text, size=11, color="000000")], before_pt=0, after_pt=6, line=1.1)


def code_like_paragraph(text: str) -> str:
    return para_xml([run_xml(text, size=10, color="444444")], before_pt=0, after_pt=4, line=1.0)


def section_props_xml() -> str:
    return (
        "<w:sectPr>"
        f"<w:pgSz w:w=\"{dxa_from_in(8.5)}\" w:h=\"{dxa_from_in(11)}\"/>"
        f"<w:pgMar w:top=\"{dxa_from_in(1)}\" w:right=\"{dxa_from_in(1)}\" w:bottom=\"{dxa_from_in(1)}\" w:left=\"{dxa_from_in(1)}\" w:header=\"708\" w:footer=\"708\" w:gutter=\"0\"/>"
        "<w:cols w:space=\"708\"/>"
        "<w:docGrid w:linePitch=\"360\"/>"
        "</w:sectPr>"
    )


def build_document_xml() -> str:
    paras = [
        title_xml("HestiaPredict - fonctionnement global du projet"),
        subtitle_xml(
            "Vue d'ensemble des trois briques principales et de la façon dont elles se partagent le travail"
        ),
        body_paragraph(
            "Le projet fonctionne comme une chaîne à trois niveaux. hestia_app est la couche visible utilisée par le personnel, hestiapredict est le cœur métier qui contrôle les règles, et hestia-ai est le service spécialisé qui produit les prévisions. L'important est de comprendre que Flutter n'accède jamais directement à l'IA : il passe par Laravel, qui reste la source de vérité pour les réservations, les paiements, les statuts et les identités."
        ),
        body_paragraph(
            "Cette séparation évite de mélanger l'interface, la logique de gestion et le calcul prédictif. Elle permet aussi de faire évoluer chaque partie sans casser les autres. On peut changer l'écran Flutter, renforcer les règles Laravel ou remplacer le moteur de prévision plus tard sans réécrire tout le projet."
        ),
        heading_xml("Pourquoi cette architecture", level=1),
        body_paragraph(
            "On a découpé le projet en trois services parce que chaque problème n'a pas la même nature. L'interface doit être fluide et simple, les règles métier doivent être sûres et traçables, et la prédiction doit pouvoir évoluer indépendamment. Si tout avait été mélangé dans une seule application, la maintenance serait plus difficile et les bugs seraient plus coûteux à corriger."
        ),
        body_paragraph(
            "L'autre raison est la robustesse. Si l'IA n'est pas disponible, le projet doit quand même permettre de créer une réservation, d'enregistrer un check-in et de facturer un client. C'est la couche Laravel qui garantit cette continuité. L'IA améliore la décision, mais elle ne doit pas empêcher l'exploitation de l'hôtel."
        ),
        body_paragraph(
            "Enfin, cette structure rend le projet plus lisible pour une soutenance. Tu peux expliquer la partie visible, la partie métier et la partie intelligence artificielle séparément, puis montrer comment elles coopèrent. C'est beaucoup plus facile à maîtriser qu'un seul bloc de code énorme."
        ),
        heading_xml("Les outils choisis", level=1),
        body_paragraph(
            "Le frontend a été fait avec Flutter Web. Le backend métier est en Laravel. Le moteur IA est en FastAPI avec Python. Les prédictions utilisent Prophet. La facturation PDF passe par dompdf côté Laravel. Le tout tourne avec des outils classiques de développement comme Composer, npm, Flutter CLI et Python virtualenv."
        ),
        body_paragraph(
            "Ce choix n'est pas arbitraire. Flutter permet d'avoir une interface web moderne avec une base de code unique et une UI responsive. Laravel apporte une structure solide pour les routes, les modèles, les contrôleurs, l'authentification et la logique de gestion. Python est le plus pratique pour manipuler les données et faire du forecasting. FastAPI permet d'exposer ce calcul comme un petit service HTTP simple à appeler."
        ),
        body_paragraph(
            "Autrement dit, chaque technologie est utilisée là où elle est la plus forte. On n'a pas choisi une seule pile par préférence, mais parce qu'elle colle au rôle de chaque brique."
        ),
        heading_xml("Pourquoi pas un autre choix", level=2),
        body_paragraph(
            "On aurait pu faire tout le projet en React, en Node.js ou en Django, mais cela aurait mélangé les responsabilités. Un backend unique aurait pu marcher, mais la partie prédictive aurait été moins isolée et moins simple à faire évoluer. Ici, Laravel garde le métier et FastAPI garde le calcul."
        ),
        body_paragraph(
            "On aurait aussi pu choisir une application mobile native, mais le besoin principal était une interface staff accessible rapidement, surtout pour la réception. Flutter Web est adapté parce qu'il reste proche d'une application desktop dans l'usage, tout en gardant une base unique pour la logique d'affichage."
        ),
        body_paragraph(
            "Pour l'IA, un moteur plus complexe n'était pas forcément utile. Le but n'était pas de faire de la recherche avancée, mais de produire une estimation de demande suffisamment bonne pour aider à fixer un prix. Prophet est pratique pour cela parce qu'il gère bien les tendances et la saisonnalité avec peu de friction."
        ),
        heading_xml("1. hestia_app", level=1),
        body_paragraph(
            "hestia_app est l'application Flutter Web côté staff. C'est l'interface de travail de la réception et des utilisateurs autorisés. Depuis cette application, on se connecte, on consulte les disponibilités, on crée ou modifie des réservations, on fait les check-ins, on suit les folios et on enregistre les paiements."
        ),
        body_paragraph(
            "Le code est organisé autour d'un client API central, d'écrans spécialisés et de petits services locaux pour la session. L'application lit l'URL du backend via la configuration, envoie ses requêtes HTTP vers Laravel, puis transforme les réponses en écrans lisibles et rapides pour un usage hôtelier."
        ),
        body_paragraph(
            "Concrètement, les écrans principaux sont la liste des réservations, le check-in, le folio, les utilisateurs administrateurs et les cartes de disponibilité. L'application aide aussi la saisie grâce à l'autocomplétion des clients existants, ce qui accélère le travail à l'accueil et limite les doublons. La session est conservée localement pour éviter de reconnecter le staff à chaque rafraîchissement."
        ),
        body_paragraph(
            "Le rôle de cette partie est donc purement opérationnel. Elle ne décide pas des règles métier et ne calcule pas les prix toute seule : elle demande les informations au backend et affiche des actions simples à exécuter par le personnel. C'est ce qui garde l'interface légère et compréhensible."
        ),
        heading_xml("Ce que gère l'application", level=2),
        body_paragraph(
            "Elle centralise la consultation et la saisie rapide. Cela inclut les réservations futures, les arrivées du jour, le changement de statut, l'ajout d'extras comme des lits ou des matelas, la consultation du folio et le suivi des acomptes ou paiements complets."
        ),
        body_paragraph(
            "Elle sert aussi de tableau de bord pratique. Le staff y voit ce qu'il faut traiter maintenant, ce qui est déjà confirmé, ce qui est à facturer, et ce qui demande une intervention administrative."
        ),
        body_paragraph(
            "Sur le plan technique, l'application lit la configuration au démarrage, conserve l'utilisateur connecté dans le stockage local du navigateur et charge les écrans en fonction du rôle. Cela évite de réinventer l'authentification à chaque page et simplifie le parcours utilisateur."
        ),
        heading_xml("2. hestia-ai", level=1),
        body_paragraph(
            "hestia-ai est le moteur de prédiction en FastAPI. Son rôle est limité mais stratégique : il reçoit un historique d'occupation, des prix de base et des paramètres de capacité, puis produit des projections de demande et des prix suggérés."
        ),
        body_paragraph(
            "Le service s'appuie sur Prophet pour modéliser les tendances, la saisonnalité et les variations de la demande. L'objectif n'est pas seulement de dire combien de chambres pourraient être occupées, mais aussi d'aider à estimer un prix cohérent avec la pression du marché, la période et la capacité restante."
        ),
        body_paragraph(
            "Avant de lancer une prédiction, il vérifie que l'historique est suffisant. Si ce n'est pas le cas, il refuse plutôt que d'inventer un résultat peu fiable. C'est important : l'IA n'est pas traitée comme une source absolue, mais comme un outil d'aide à la décision qui dépend de données propres et en quantité suffisante."
        ),
        body_paragraph(
            "Ce composant est volontairement stateless. Il expose surtout deux routes, health pour vérifier qu'il répond et predict pour produire les résultats. Il ne gère ni authentification staff, ni base de données, ni réservation : il calcule, puis Laravel interprète le résultat et l'encadre avec ses propres règles métier."
        ),
        heading_xml("Comment l'IA est utilisée", level=2),
        body_paragraph(
            "Laravel rassemble l'historique utile, les prix de base, les capacités par catégorie et les règles de yield, puis envoie ces informations au moteur IA. Le moteur renvoie des valeurs de prédiction, et Laravel décide ensuite du prix réellement affiché ou utilisé."
        ),
        body_paragraph(
            "Cela signifie que l'IA n'a jamais le dernier mot seule. Même si elle propose un prix plus élevé ou plus bas, la couche métier peut le plafonner, le relever ou le contraindre selon les règles du projet. C'est ce qui évite les comportements incohérents."
        ),
        body_paragraph(
            "Le service est volontairement minimal. Il n'a pas besoin de gérer la connexion du staff, ni la base hôtelière, ni les PDF. Il reçoit des données, calcule, répond. Cette simplicité permet de le tester séparément et de le remplacer plus facilement si un jour on décide d'utiliser un autre moteur de prévision."
        ),
        heading_xml("Schéma textuel de l'architecture", level=1),
        body_paragraph(
            "On a choisi un schéma textuel parce qu'il se lit vite et qu'il permet d'expliquer le flux en soutenance sans passer par un dessin complexe. C'est utile ici parce que l'objectif est de comprendre qui appelle quoi et pourquoi."
        ),
        code_like_paragraph("hestia_app (Flutter Web)"),
        code_like_paragraph("  -> HTTP /api"),
        code_like_paragraph("  -> hestiapredict (Laravel)"),
        code_like_paragraph("       -> AvailabilityService"),
        code_like_paragraph("       -> BookingService"),
        code_like_paragraph("       -> YieldService"),
        code_like_paragraph("       -> PMSController"),
        code_like_paragraph("       -> Base SQLite"),
        code_like_paragraph("       -> HTTP /predict"),
        code_like_paragraph("       -> hestia-ai (FastAPI)"),
        code_like_paragraph("            -> forecasting.py"),
        code_like_paragraph("            -> Prophet"),
        body_paragraph(
            "Ce schéma montre que Flutter ne parle pas directement à la base ni au moteur IA. On a choisi cette séparation parce qu'elle protège la logique métier et réduit les effets de bord."
        ),
        body_paragraph(
            "Laravel est au centre du flux. On l'a choisi parce qu'il fournit naturellement les validations, les routes et les transactions, ce qui est exactement ce qu'il faut pour un système de réservation."
        ),
        body_paragraph(
            "FastAPI est isolé derrière une route dédiée. On l'a choisi parce qu'il permet d'exposer un calcul prédictif léger sans encombrer le backend principal."
        ),
        heading_xml("Glossaire technique", level=1),
        body_paragraph(
            "API: interface de communication entre programmes. On a choisi ce modèle parce qu'il sépare proprement le frontend du backend et qu'il rend le projet plus maintenable."
        ),
        body_paragraph(
            "HTTP: protocole d'échange réseau. On l'a choisi parce qu'il est standard, simple et compatible avec les trois briques du projet."
        ),
        body_paragraph(
            "JSON: format texte pour transporter des données. On l'a choisi parce qu'il est léger et facile à parser partout."
        ),
        body_paragraph(
            "Frontend: partie visible par l'utilisateur. On a choisi Flutter Web pour cette couche parce qu'il produit une interface moderne et réactive avec une seule base de code."
        ),
        body_paragraph(
            "Backend: partie qui applique les règles et stocke les données. On a choisi Laravel pour cette couche parce qu'il structure bien les flux métier d'un hôtel."
        ),
        body_paragraph(
            "Base de données: stockage persistant des informations. On a choisi SQLite en local parce qu'elle est simple à mettre en place et suffisante pour un projet de développement."
        ),
        body_paragraph(
            "Session: état temporaire de l'utilisateur. On a choisi `sessionStorage` côté web parce qu'il permet de garder le staff connecté dans le navigateur sans complexité inutile."
        ),
        body_paragraph(
            "Cache: mémoire temporaire pour accélérer l'affichage. On a choisi le cache Laravel parce qu'il évite de recalculer les mêmes disponibilités à chaque requête."
        ),
        body_paragraph(
            "Transaction: bloc d'opérations atomiques. On a choisi les transactions pour les réservations et paiements parce qu'elles empêchent les écritures incomplètes."
        ),
        body_paragraph(
            "Fallback: plan de secours. On a choisi un fallback pour l'IA parce qu'il garantit que le projet reste utilisable même si le service de prévision tombe."
        ),
        body_paragraph(
            "Yield management: tarification selon la demande. On a choisi cette logique parce qu'elle permet d'adapter les prix à l'occupation réelle."
        ),
        body_paragraph(
            "Prophet: bibliothèque de prévision temporelle. On l'a choisie parce qu'elle sait exploiter les historiques de réservation pour projeter l'occupation future."
        ),
        body_paragraph(
            "dompdf: générateur PDF côté PHP. On l'a choisi parce qu'il produit des factures imprimables directement depuis le backend."
        ),
        body_paragraph(
            "FastAPI: framework Python pour API rapides. On l'a choisi parce qu'il est léger, lisible et adapté à un microservice d'IA."
        ),
        body_paragraph(
            "Flutter Web: framework d'interface web. On l'a choisi parce qu'il donne un rendu rapide et cohérent pour le staff."
        ),
        heading_xml("Les bases techniques", level=1),
        body_paragraph(
            "Pour comprendre le projet, il faut repartir des bases. Le frontend est la partie visible par le staff. Le backend est la partie qui traite et sécurise les informations. L'API est le contrat entre les deux. Quand Flutter veut des données, il envoie une requête HTTP. Laravel répond avec du JSON. Quand Laravel a besoin de prédire, il envoie lui aussi du JSON vers FastAPI."
        ),
        body_paragraph(
            "JSON est important parce qu'il est simple à lire et à générer. C'est un format texte composé de paires clé-valeur et de tableaux. C'est le langage commun entre les services du projet. Grâce à lui, le frontend, le backend métier et le moteur IA peuvent échanger sans partager la même technologie interne."
        ),
        body_paragraph(
            "On peut aussi voir le projet comme trois couches. La couche interface affiche les écrans. La couche métier vérifie les règles et modifie les données. La couche calcul prédictif anticipe l'occupation et aide au yield management. Cette séparation est plus propre qu'un seul bloc de code géant."
        ),
        heading_xml("Outils et définitions", level=1),
        body_paragraph(
            "Flutter Web est un framework pour créer une interface web avec Dart. Ici, il est utilisé parce qu'il permet d'avoir des écrans riches, un rendu rapide et une logique commune sur tout le projet frontend."
        ),
        body_paragraph(
            "Laravel est un framework PHP qui facilite la création de routes, de contrôleurs, de modèles, de services et de validations. On l'utilise pour la partie métier car il est très adapté aux applications structurées autour d'une base de données."
        ),
        body_paragraph(
            "FastAPI est un framework Python pour créer des API rapidement. On l'utilise ici parce qu'il est léger, lisible et qu'il s'intègre facilement avec les bibliothèques de calcul en Python."
        ),
        body_paragraph(
            "Prophet est une bibliothèque de prévision temporelle. Une prévision temporelle consiste à estimer l'évolution future d'une grandeur mesurée dans le temps. Dans ce projet, cette grandeur est l'occupation d'une catégorie de chambre."
        ),
        body_paragraph(
            "dompdf est une bibliothèque PHP qui génère des PDF à partir de vues ou de données HTML. Elle est utile pour produire des factures imprimables sans passer par un outil externe."
        ),
        body_paragraph(
            "Composer gère les dépendances PHP, npm gère les dépendances frontend de Laravel, Flutter CLI sert à compiler l'application web Flutter, et Python virtualenv isole l'environnement du moteur IA pour éviter les conflits de paquets."
        ),
        body_paragraph(
            "Le projet repose aussi sur SQLite en local. SQLite est une base de données simple qui s'installe très facilement. Pour un projet annuel, c'est pratique parce qu'on peut démarrer vite sans serveur de base de données dédié."
        ),
        heading_xml("Organisation du dépôt", level=1),
        body_paragraph(
            "Le dépôt est séparé en trois dossiers principaux. `hestia_app` contient l'application Flutter Web. `hestiapredict` contient le backend Laravel. `hestia-ai` contient le moteur Python de prévision."
        ),
        body_paragraph(
            "Cette organisation évite de mélanger les responsabilités. Les écrans, les routes métier et le calcul prédictif restent dans des espaces distincts. C'est plus simple à comprendre, plus simple à tester et plus simple à présenter."
        ),
        body_paragraph(
            "On trouve aussi des scripts de lancement comme `dev.sh` et `start_project.sh`, ainsi que de la documentation API. Cela montre que le projet a été pensé pour être relancé de manière reproductible."
        ),
        heading_xml("Fonctionnement de l'interface", level=1),
        body_paragraph(
            "Dans `hestia_app`, le point d'entrée est `main.dart`. Ce fichier démarre l'application, charge l'utilisateur enregistré en session et choisit soit l'écran de login, soit le dashboard du personnel."
        ),
        body_paragraph(
            "La configuration de l'API est injectée via `API_BASE_URL`. Cela signifie que l'adresse du backend n'est pas codée en dur dans le programme. On peut la changer au moment de la compilation, ce qui rend le projet plus flexible entre local, test et autre environnement."
        ),
        code_like_paragraph("API_BASE_URL = http://localhost:8000 par défaut"),
        body_paragraph(
            "Le client HTTP centralisé se trouve dans `api_client.dart`. Il construit les URLs, ajoute les headers JSON et impose un timeout. Le fait d'avoir un client unique évite de répéter la logique de requête dans chaque écran."
        ),
        body_paragraph(
            "Le timeout est important car il empêche l'interface de rester bloquée si le backend répond mal. Si une requête dure trop longtemps, l'utilisateur doit recevoir un retour plutôt que de voir l'écran figé."
        ),
        body_paragraph(
            "La session utilisateur est gérée par `SessionService`. Sur le web, cette session est stockée dans `window.sessionStorage`. Cela veut dire que les données restent dans le navigateur tant que l'onglet ou la session existe, puis disparaissent quand on ferme le contexte. On utilise ce mécanisme pour conserver l'utilisateur connecté sans recréer un système d'authentification complexe côté Flutter."
        ),
        code_like_paragraph("Clé de session utilisée : user_session"),
        body_paragraph(
            "L'objet `AppUser` est sérialisé en JSON avant d'être stocké. Au retour, il est rechargé et reconstruit. Si le JSON est invalide, la session est effacée pour éviter d'utiliser une donnée corrompue."
        ),
        body_paragraph(
            "Le provider de session déclenche des notifications quand l'utilisateur se connecte ou se déconnecte. Cela permet à l'interface de se mettre à jour sans rafraîchissement manuel."
        ),
        body_paragraph(
            "Certaines vues utilisent aussi un cache local avec `SharedPreferences`. Sur le web, ce type de stockage agit comme une mémoire persistante légère côté navigateur. Le but n'est pas de remplacer la base de données, mais de garder une copie temporaire pour accélérer l'affichage ou survivre à une panne courte du backend."
        ),
        heading_xml("Autocomplétion client", level=1),
        body_paragraph(
            "Le champ d'autocomplétion du client est un bon exemple de fonctionnalité bien découpée. L'utilisateur tape un nom, un téléphone ou un numéro de document. Après un délai de frappe court, le composant lance une recherche distante."
        ),
        body_paragraph(
            "Le composant attend au moins deux caractères avant d'appeler l'API. C'est un choix simple pour éviter d'envoyer des requêtes inutiles à chaque lettre. Ensuite, les résultats sont affichés sous le champ, avec le nom, le numéro de document, le téléphone et, si utile, le compteur de fidélité."
        ),
        body_paragraph(
            "La logique de recherche elle-même se trouve dans `ClientController`. Le backend compare le texte saisi aux champs du client et à certains champs liés à la réservation. Les résultats sont ensuite triés par fidélité et date de mise à jour."
        ),
        body_paragraph(
            "La déduplication est importante parce qu'un même client peut apparaître plusieurs fois via des données proches. Le modèle `ClientProfile` calcule une clé normalisée à partir du nom, du téléphone ou du document d'identité pour éviter d'afficher le même client plusieurs fois."
        ),
        heading_xml("Réservations et prix", level=1),
        body_paragraph(
            "Quand on crée une réservation, le frontend envoie les dates, les chambres, les extras et éventuellement les prix calculés. Le backend vérifie d'abord qu'aucune chambre demandée n'est déjà occupée sur la période."
        ),
        body_paragraph(
            "Cette vérification repose sur les réservations actives. Une réservation active est une réservation qui compte encore pour la disponibilité. Le système considère généralement les statuts `en_attente` et `arrive` comme actifs."
        ),
        body_paragraph(
            "Si la source de la réservation est Booking, le backend applique des règles supplémentaires. Cela sert à limiter Booking à une certaine catégorie de chambres. C'est un exemple concret de règle métier codée côté serveur, pas côté interface."
        ),
        body_paragraph(
            "Le prix réel de la chambre est stocké dans `price_snapshot_ariary`. Ce champ est essentiel parce qu'il conserve le prix au moment précis de la vente. Ainsi, si les prix de base changent demain, le séjour déjà vendu garde son prix historique."
        ),
        body_paragraph(
            "Les extras comme les lits ou les matelas sont aussi contrôlés par capacité. Le backend vérifie combien d'extras restent disponibles par nuit avant d'accepter la réservation ou la modification."
        ),
        heading_xml("Check-in et folio", level=1),
        body_paragraph(
            "Le check-in transforme une réservation en séjour réellement arrivé. Le staff saisit les informations d'identité et les données nécessaires au dossier client. Le backend met alors la réservation au statut `arrive` et crée ou met à jour le profil guest."
        ),
        body_paragraph(
            "Le folio est la facture en cours du séjour. Il regroupe les lignes de chambre, les extras, les dépôts et les paiements. Le backend calcule automatiquement les totaux, le solde restant et l'état du document."
        ),
        body_paragraph(
            "Le frontend n'invente pas les montants. Il affiche ce que Laravel a déjà calculé. C'est une règle de bonne conception: une seule source de vérité pour les montants financiers."
        ),
        body_paragraph(
            "Quand un paiement est ajouté, la facture est verrouillée pendant l'opération. Cela évite que deux utilisateurs valident en même temps un montant incohérent. Si le solde tombe à zéro, la facture peut passer au statut payé."
        ),
        body_paragraph(
            "Le système garde aussi une trace d'audit. L'audit enregistre qui a fait l'action, quand et sur quelle réservation. Cela aide beaucoup pour le contrôle et la compréhension du fonctionnement."
        ),
        heading_xml("Yield et prédiction", level=1),
        body_paragraph(
            "Le module de yield management commence par constituer un historique exploitable. Le backend parcourt les réservations actives, les répartit par jour et par catégorie de chambre, puis construit une série temporelle de l'occupation."
        ),
        body_paragraph(
            "Ensuite Laravel envoie ces données à FastAPI sur `/predict`. Le moteur Python ne reçoit pas la base complète du projet; il reçoit seulement le jeu de données utile pour la prévision. C'est plus propre, plus léger et plus sûr."
        ),
        body_paragraph(
            "FastAPI s'appuie sur Prophet pour prédire les valeurs futures. Prophet essaie de détecter la tendance globale, les cycles hebdomadaires ou annuels et les effets de saison. Dans un hôtel, ces effets sont importants parce que l'occupation n'est jamais plate."
        ),
        body_paragraph(
            "Le moteur prend aussi en compte des règles métiers additionnelles. Par exemple, certaines périodes comme les mois cycloniques sont pénalisées, tandis que les week-ends peuvent être traités différemment. Cela permet de rapprocher la prévision de la réalité locale."
        ),
        body_paragraph(
            "Le prix suggéré n'est pas juste un résultat brut du modèle. Le backend applique ensuite un multiplicateur de yield, plafonné, pour éviter des hausses trop brutales. Les chambres fixes restent au prix plancher."
        ),
        body_paragraph(
            "Si FastAPI ne répond pas, Laravel renvoie immédiatement un fallback. Le fallback applique les prix de base et garde le système fonctionnel. Ce n'est pas un bug: c'est une stratégie de sécurité prévue dès le départ."
        ),
        body_paragraph(
            "Le fallback est rendu possible grâce à des timeouts courts sur la requête HTTP. Laravel préfère échouer vite et basculer sur une valeur sûre plutôt que bloquer l'application en attendant un service externe."
        ),
        heading_xml("Pourquoi le moteur est utile", level=1),
        body_paragraph(
            "Quand on parle du moteur, on parle du service de prévision dans `hestia-ai`. Son intérêt est d'ajouter une intelligence temporelle au projet. Sans ce moteur, le système pourrait gérer les réservations, mais il perdrait la partie anticipation et tarification dynamique."
        ),
        body_paragraph(
            "Un moteur plus complexe n'était pas forcément utile parce que le besoin n'était pas de faire de la recherche avancée. Il fallait surtout prédire l'occupation et suggérer un prix plausible, stable et compréhensible."
        ),
        body_paragraph(
            "Le moteur reste interchangeable: si une autre méthode de prévision devait être utilisée plus tard, Laravel ne devrait pas être entièrement réécrit. Il suffirait de garder le même contrat d'entrée et de sortie."
        ),
        heading_xml("Exemples de flux", level=1),
        body_paragraph(
            "Exemple 1: le staff ouvre la liste des réservations. Flutter appelle Laravel. Laravel lit la base et renvoie les réservations filtrées. Flutter affiche la liste."
        ),
        body_paragraph(
            "Exemple 2: le staff crée une réservation. Flutter valide le formulaire, envoie les données, Laravel vérifie les chambres et les prix, puis enregistre la réservation en transaction."
        ),
        body_paragraph(
            "Exemple 3: le dashboard affiche une estimation de revenu. Laravel construit l'historique, appelle FastAPI, recadre les prix et renvoie un tableau de prédictions."
        ),
        body_paragraph(
            "Exemple 4: le client arrive. Le staff fait le check-in, la réservation passe à `arrive`, le guest est créé ou mis à jour, puis le folio devient utilisable."
        ),
        body_paragraph(
            "Exemple 5: un paiement est enregistré. Le backend vérifie le solde, écrit le paiement, recalcule la facture et met à jour l'état final du séjour."
        ),
        heading_xml("3. hestiapredict", level=1),
        body_paragraph(
            "hestiapredict est le backend principal en Laravel. C'est lui qui centralise les règles métier, la base de données, les réservations, les check-ins, les folios, les paiements et la génération de documents PDF."
        ),
        body_paragraph(
            "Il joue aussi le rôle d'orchestrateur : il reçoit les demandes du frontend Flutter, applique les règles de disponibilité, calcule les prix autorisés, appelle hestia-ai via AI_ENGINE_URL quand il faut une prédiction, puis retombe sur un mode fallback si l'IA n'est pas disponible."
        ),
        body_paragraph(
            "Dans la pratique, Laravel garde la trace de tout ce qui doit être audité : prix capturé au moment de la vente, statut des réservations, historique complet, rôle de l'utilisateur et opérations sensibles. C'est cette couche qui empêche le reste du système de devenir incohérent. Elle expose l'API consommée par Flutter et peut aussi servir un dashboard d'administration."
        ),
        body_paragraph(
            "Grâce à cette organisation, l'application reste utilisable même si le moteur IA tombe, car la vente, le check-in et la facturation reposent sur Laravel et non sur la prédiction. L'IA améliore les décisions tarifaires, mais elle ne bloque pas le fonctionnement opérationnel."
        ),
        body_paragraph(
            "C'est aussi là que se trouvent les règles de sécurité métier. Par exemple, certaines actions ne sont possibles qu'après check-in, certains prix sont verrouillés, certains historiques sont réservés à l'administration et les opérations critiques sont enregistrées dans la base de données."
        ),
        heading_xml("Ce que fait Laravel précisément", level=2),
        body_paragraph(
            "Laravel reçoit les requêtes du frontend, valide les données, applique les statuts, écrit dans la base de données et renvoie les réponses prêtes à afficher. Il gère aussi les règles de disponibilité des chambres, la création de réservation multi-chambres, le stockage du prix capturé au moment de la vente et les calculs de fallback si l'IA ne répond pas."
        ),
        body_paragraph(
            "C'est également lui qui sécurise la logique de métier. Par exemple, le projet distingue un client attendu, un client arrivé et un client annulé. Il empêche certaines opérations après check-in et conserve un historique d'audit pour savoir qui a fait quoi."
        ),
        body_paragraph(
            "La génération PDF part aussi d'ici. Le backend construit la facture, puis le frontend Flutter peut l'afficher, la partager ou l'imprimer. C'est plus propre que de laisser l'interface reconstruire elle-même la logique de facturation."
        ),
        heading_xml("Le parcours complet", level=2),
        body_paragraph(
            "1. Le staff ouvre hestia_app et se connecte."
        ),
        body_paragraph(
            "2. L'application appelle hestiapredict pour afficher les disponibilités ou créer une réservation."
        ),
        body_paragraph(
            "3. Laravel décide du prix et des règles à appliquer."
        ),
        body_paragraph(
            "4. Si une estimation dynamique est nécessaire, Laravel interroge hestia-ai."
        ),
        body_paragraph(
            "5. Le résultat revient dans Laravel, puis remonte dans Flutter pour être affiché au staff."
        ),
        body_paragraph(
            "6. Une fois la réservation confirmée, le check-in, le folio et les paiements restent gérés par Laravel, avec une trace claire de chaque action."
        ),
        heading_xml("Déroulé d'une réservation", level=1),
        body_paragraph(
            "Quand une réservation est créée, le staff cherche d'abord le client ou le crée si besoin. Ensuite il choisit les chambres disponibles, les dates, les extras éventuels et la source de la réservation. hestiapredict calcule si les chambres sont libres et quel prix doit être retenu."
        ),
        body_paragraph(
            "Une fois la réservation enregistrée, le système garde une trace du prix exact utilisé au moment de la vente. C'est important parce que le prix peut évoluer plus tard. Le projet doit donc mémoriser ce qui a été réellement vendu, pas seulement le prix affiché sur l'écran."
        ),
        body_paragraph(
            "Au moment du check-in, on complète les informations légales, on confirme l'arrivée du client et on passe la réservation dans le statut approprié. À partir de là, le folio devient central : il regroupe les frais de chambre, les extras, les remises éventuelles et les paiements reçus."
        ),
        body_paragraph(
            "Le résultat final est un suivi complet du séjour. On peut voir le client, la réservation, les chambres, le statut, les montants et l'historique des paiements. C'est cette continuité qui fait que le système ressemble à un vrai PMS hôtelier et pas seulement à un formulaire de réservation."
        ),
        heading_xml("Ce qu'il faut savoir pour l'oral", level=1),
        body_paragraph(
            "Si on te demande pourquoi on a fait ce projet comme ça, la réponse courte est : pour séparer l'affichage, la logique métier et l'IA. Si on te demande pourquoi Laravel, tu peux dire qu'il structure bien les règles, les routes et la base de données. Si on te demande pourquoi FastAPI, tu peux dire qu'il expose un moteur de calcul simple et rapide."
        ),
        body_paragraph(
            "Si on te demande pourquoi Flutter Web, la bonne idée est de dire que l'interface doit être rapide à utiliser au bureau, avec une seule base de code et un rendu moderne. Si on te demande pourquoi Prophet, tu peux répondre que c'est un outil adapté à la prévision de séries temporelles comme l'occupation hôtelière."
        ),
        body_paragraph(
            "Et si on te demande ce qui rend le projet fiable, il faut insister sur le fallback. Même si l'IA est indisponible, le backend métier continue de fonctionner. C'est une réponse importante à donner, parce que ça montre que le projet n'est pas juste démonstratif : il a été pensé pour rester exploitable."
        ),
        heading_xml("Mise en route", level=1),
        body_paragraph(
            "Pour lancer le projet, on part de la racine du dépôt. Le script `dev.sh` démarre l'environnement complet. Le frontend Flutter est construit puis servi, Laravel expose l'API métier, et le moteur Python répond sur son propre port. Cette séparation des ports rend le débogage plus simple."
        ),
        body_paragraph(
            "En local, le projet utilise aussi SQLite comme base légère. C'est pratique pour le développement parce que ça évite une installation de base de données plus lourde. En production ou pour une version plus avancée, on pourrait migrer vers MySQL ou PostgreSQL, mais pour un projet annuel SQLite permet d'aller vite et de garder un environnement simple."
        ),
        body_paragraph(
            "On a aussi un script de build Flutter et des commandes de test dans chaque composant. Cela montre que le projet n'est pas juste assemblé à la main : il est pensé pour être relancé, vérifié et maintenu."
        ),
        heading_xml("Explication fichier par fichier", level=1),
        body_paragraph(
            "Cette partie détaille les fichiers les plus importants. On a choisi de ne pas lister tout le dépôt ligne par ligne parce que cela noierait l'essentiel. À la place, on explique les fichiers qui portent réellement le fonctionnement du projet et la raison de leur présence."
        ),
        heading_xml("Frontend Flutter", level=2),
        body_paragraph(
            "`hestia_app/lib/main.dart` est le point de démarrage de l'interface. On l'a choisi comme point central parce qu'il initialise l'application, lit la session et décide si l'utilisateur doit voir le login ou le tableau de bord. Cela apporte un démarrage clair et contrôlé."
        ),
        body_paragraph(
            "`hestia_app/lib/core/app_config.dart` contient l'URL de l'API. On a choisi de mettre cette valeur dans une configuration séparée parce qu'on peut ainsi changer d'environnement sans réécrire le code, ce qui rend le projet plus flexible."
        ),
        body_paragraph(
            "`hestia_app/lib/services/api_client.dart` centralise les requêtes HTTP. On l'a choisi parce qu'un seul client évite les répétitions, ajoute un timeout commun et garde la logique réseau propre."
        ),
        body_paragraph(
            "`hestia_app/lib/services/session_service.dart` sauvegarde et recharge l'utilisateur. On l'a choisi parce qu'il simplifie la reconnexion locale et évite de redemander le login à chaque rafraîchissement."
        ),
        body_paragraph(
            "`hestia_app/lib/services/session_storage_web.dart` et `session_storage_stub.dart` isolent le stockage web et le fallback hors web. On a choisi ce duo parce qu'il permet au même code Flutter de fonctionner correctement sur web et dans des tests, ce qui apporte de la robustesse."
        ),
        body_paragraph(
            "`hestia_app/lib/services/client_search_service.dart` interroge l'API de recherche client. On l'a choisi parce qu'il sépare la recherche métier du champ de saisie, ce qui rend la réutilisation plus simple."
        ),
        body_paragraph(
            "`hestia_app/lib/widgets/client_autocomplete_field.dart` affiche l'autocomplétion. On l'a choisi comme widget dédié parce qu'il encapsule le délai de frappe, l'affichage des suggestions et la sélection, ce qui évite de dupliquer ce comportement dans plusieurs écrans."
        ),
        body_paragraph(
            "`hestia_app/lib/widgets/availability_card.dart` présente les disponibilités sous forme lisible. On l'a choisi pour rendre la consultation rapide et éviter d'afficher les catégories sous une forme trop brute."
        ),
        body_paragraph(
            "`hestia_app/lib/screens/reservations_list_page.dart` gère la liste, les filtres et les actions sur les réservations. On l'a choisi parce qu'il concentre le cœur du travail quotidien de la réception."
        ),
        body_paragraph(
            "`hestia_app/lib/screens/checkin_page.dart` sert au check-in. On l'a choisi pour isoler le flux d'arrivée, car cette opération a beaucoup de champs et des règles particulières."
        ),
        body_paragraph(
            "`hestia_app/lib/screens/folio_page.dart` affiche la facture du séjour. On l'a choisi pour centraliser les montants, les dépôts, les paiements et les documents PDF, ce qui évite de disperser la comptabilité dans l'interface."
        ),
        body_paragraph(
            "`hestia_app/lib/screens/admin_users_page.dart` gère les comptes administrateurs. On l'a choisi pour séparer les fonctions sensibles du reste du staff, ce qui améliore la sécurité fonctionnelle."
        ),
        body_paragraph(
            "`hestia_app/lib/models/app_user.dart`, `client_profile.dart` et `reservation.dart` décrivent les objets manipulés par l'application. On les a choisis parce qu'ils donnent une structure claire aux données et évitent d'utiliser des cartes JSON opaques partout."
        ),
        body_paragraph(
            "`hestia_app/lib/providers/session_provider.dart` diffuse l'état de connexion dans l'interface. On l'a choisi parce qu'il rend l'UI réactive sans propager la logique de session dans chaque écran."
        ),
        body_paragraph(
            "`hestia_app/lib/core/formatters.dart` regroupe le formatage des nombres, des dates et des montants. On l'a choisi pour garder une présentation homogène dans toute l'application."
        ),
        heading_xml("Backend Laravel", level=2),
        body_paragraph(
            "`hestiapredict/routes/api.php` déclare les routes API. On l'a choisi comme point d'entrée réseau parce qu'il montre immédiatement quelles actions sont disponibles et comment elles sont protégées par débit."
        ),
        body_paragraph(
            "`hestiapredict/routes/web.php` gère les routes du dashboard web. On l'a choisi parce que le dashboard a besoin de sessions serveur et de pages web séparées des routes JSON."
        ),
        body_paragraph(
            "`hestiapredict/app/Http/Controllers/HotelManagementController.php` orchestre les appels principaux du frontend. On l'a choisi comme contrôleur central parce qu'il relie disponibilité, réservation, utilisateurs et yield sans mélanger toute la logique interne."
        ),
        body_paragraph(
            "`hestiapredict/app/Http/Controllers/ClientController.php` gère la recherche client. On l'a choisi parce que la recherche d'identité est une fonctionnalité autonome qui mérite sa propre route et sa propre logique."
        ),
        body_paragraph(
            "`hestiapredict/app/Http/Controllers/PMSController.php` traite le check-in, le folio, les paiements et les PDF. On l'a choisi parce que toutes ces actions relèvent du PMS hôtelier et doivent partager la même logique de séjour."
        ),
        body_paragraph(
            "`hestiapredict/app/Services/AvailabilityService.php` calcule les disponibilités et le cache. On l'a choisi parce qu'il encapsule une logique que plusieurs contrôleurs doivent réutiliser."
        ),
        body_paragraph(
            "`hestiapredict/app/Services/BookingService.php` crée et modifie les réservations. On l'a choisi parce que cette logique est riche en validations, en transactions et en règles de prix."
        ),
        body_paragraph(
            "`hestiapredict/app/Services/YieldService.php` gère la prédiction et le fallback. On l'a choisi parce qu'il est le pont entre le métier et l'IA, ce qui le rend indispensable pour la tarification dynamique."
        ),
        body_paragraph(
            "`hestiapredict/app/Services/AuthService.php` et `DashboardAuthController.php` gèrent le login du dashboard. On les a choisis parce qu'ils isolent l'accès administrateur du reste de l'API."
        ),
        body_paragraph(
            "`hestiapredict/app/Http/Middleware/EnsureAdminDashboard.php` protège l'accès admin. On l'a choisi parce qu'un middleware est la bonne place pour bloquer une page avant même qu'elle soit rendue."
        ),
        body_paragraph(
            "`hestiapredict/app/Models/Reservation.php`, `Room.php`, `Invoice.php`, `InvoiceItem.php`, `Payment.php`, `Guest.php` et `ReservationAudit.php` représentent les entités métier. On les a choisis parce qu'ils donnent une structure solide aux données et rendent les relations entre séjour, facture et audit faciles à suivre."
        ),
        body_paragraph(
            "`hestiapredict/app/Support/PhoneNumber.php` normalise les numéros. On l'a choisi parce qu'un format unique rend la recherche, la comparaison et la déduplication plus fiables."
        ),
        body_paragraph(
            "`hestiapredict/app/Http/Resources/RoomResource.php` et `ReservationResource.php` formatent les réponses API. On les a choisis parce qu'ils permettent de contrôler précisément ce que Flutter reçoit."
        ),
        heading_xml("Moteur IA", level=2),
        body_paragraph(
            "`hestia-ai/app/main.py` expose l'API FastAPI. On l'a choisi comme point d'entrée parce qu'il montre immédiatement la santé du service et l'accès à la prédiction."
        ),
        body_paragraph(
            "`hestia-ai/app/models.py` définit le format des données reçues. On l'a choisi parce que des modèles explicites évitent les erreurs de structure et rendent les échanges avec Laravel plus sûrs."
        ),
        body_paragraph(
            "`hestia-ai/app/config.py` contient les règles par défaut. On l'a choisi parce qu'il centralise les seuils, les capacités et les stratégies de yield, ce qui facilite les ajustements."
        ),
        body_paragraph(
            "`hestia-ai/app/services/forecasting.py` fait le vrai calcul. On l'a choisi comme cœur du moteur parce qu'il transforme l'historique en prédiction, puis la prédiction en prix suggéré."
        ),
        body_paragraph(
            "`hestia-ai/requirements.txt` liste les dépendances. On l'a choisi pour rendre l'environnement Python reproductible et éviter les différences entre machines."
        ),
        heading_xml("Résumé technique à retenir", level=1),
        body_paragraph(
            "hestia_app affiche et guide l'utilisateur. hestiapredict décide, stocke et sécurise. hestia-ai prédit. Le backend métier reste le chef d'orchestre, parce que c'est lui qui doit garantir la cohérence du séjour, du prix et du paiement."
        ),
        body_paragraph(
            "Le point le plus important pour ta présentation est de montrer que le système n'est pas seulement une addition de technologies. C'est un flux logique : une interface, un cerveau métier, et un moteur de prévision qui n'est qu'un assistant. C'est cette idée qu'il faut savoir expliquer clairement."
        ),
        body_paragraph(
            "En résumé, hestia_app présente les données, hestiapredict décide et enregistre, et hestia-ai prédit. C'est cette séparation qui rend le projet simple à maintenir, plus robuste à la panne d'un service, et plus facile à faire évoluer sans casser le reste."
        ),
        section_props_xml(),
    ]

    return (
        '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        '<w:document xmlns:wpc="http://schemas.microsoft.com/office/word/2010/wordprocessingCanvas" '
        'xmlns:cx="http://schemas.microsoft.com/office/drawing/2014/chartex" '
        'xmlns:cx1="http://schemas.microsoft.com/office/drawing/2015/9/8/chartex" '
        'xmlns:cx2="http://schemas.microsoft.com/office/drawing/2015/10/21/chartex" '
        'xmlns:cx3="http://schemas.microsoft.com/office/drawing/2016/5/9/chartex" '
        'xmlns:cx4="http://schemas.microsoft.com/office/drawing/2016/5/10/chartex" '
        'xmlns:cx5="http://schemas.microsoft.com/office/drawing/2016/5/11/chartex" '
        'xmlns:cx6="http://schemas.microsoft.com/office/drawing/2016/5/12/chartex" '
        'xmlns:cx7="http://schemas.microsoft.com/office/drawing/2016/5/13/chartex" '
        'xmlns:cx8="http://schemas.microsoft.com/office/drawing/2016/5/14/chartex" '
        'xmlns:mc="http://schemas.openxmlformats.org/markup-compatibility/2006" '
        'xmlns:m="http://schemas.openxmlformats.org/officeDocument/2006/math" '
        'xmlns:aink="http://schemas.microsoft.com/office/drawing/2016/ink" '
        'xmlns:am3d="http://schemas.microsoft.com/office/drawing/2017/model3d" '
        'xmlns:oel="http://schemas.microsoft.com/office/2019/extlst" '
        'xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships" '
        'xmlns:wp14="http://schemas.microsoft.com/office/word/2010/wordprocessingDrawing" '
        'xmlns:wp="http://schemas.openxmlformats.org/drawingml/2006/wordprocessingDrawing" '
        'xmlns:wpg="http://schemas.microsoft.com/office/word/2010/wordprocessingGroup" '
        'xmlns:wpi="http://schemas.microsoft.com/office/word/2010/wordprocessingInk" '
        'xmlns:wps="http://schemas.microsoft.com/office/word/2010/wordprocessingShape" '
        'xmlns:o="urn:schemas-microsoft-com:office:office" '
        'xmlns:v="urn:schemas-microsoft-com:vml" '
        'xmlns:w10="urn:schemas-microsoft-com:office:word" '
        'xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main" '
        'xmlns:w14="http://schemas.microsoft.com/office/word/2010/wordml" '
        'xmlns:w15="http://schemas.microsoft.com/office/word/2012/wordml" '
        'xmlns:w16cex="http://schemas.microsoft.com/office/word/2018/wordml/cex" '
        'xmlns:w16cid="http://schemas.microsoft.com/office/word/2016/wordml/cid" '
        'xmlns:w16="http://schemas.microsoft.com/office/word/2018/wordml" '
        'xmlns:w16sdtdh="http://schemas.microsoft.com/office/word/2020/wordml/sdtdatahash" '
        'xmlns:w16sdtfl="http://schemas.microsoft.com/office/word/2024/wordml/sdtformatlock" '
        'xmlns:w16se="http://schemas.microsoft.com/office/word/2015/wordml/symex" '
        'xmlns:w16du="http://schemas.microsoft.com/office/word/2023/wordml/word16du" '
        'xmlns:wne="urn:schemas-microsoft-com:office:word" '
        'mc:Ignorable="w14 w15 w16se w16cid w16 w16cex w16sdtdh w16sdtfl w16du wp14">'
        "<w:body>"
        + "".join(paras)
        + "</w:body></w:document>"
    )


def write_metadata(zipf: ZipFile) -> None:
    now = datetime.now(timezone.utc).replace(microsecond=0).isoformat().replace("+00:00", "Z")
    core = (
        '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        '<cp:coreProperties xmlns:cp="http://schemas.openxmlformats.org/package/2006/metadata/core-properties" '
        'xmlns:dc="http://purl.org/dc/elements/1.1/" '
        'xmlns:dcterms="http://purl.org/dc/terms/" '
        'xmlns:dcmitype="http://purl.org/dc/dcmitype/" '
        'xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance">'
        "<dc:title>HestiaPredict - fonctionnement global du projet</dc:title>"
        "<dc:subject>Synthèse technique</dc:subject>"
        "<dc:creator>Codex</dc:creator>"
        "<cp:keywords>HestiaPredict, hestia_app, hestia-ai, hestiapredict</cp:keywords>"
        "<dc:description>Document de synthèse expliquant le fonctionnement global du projet en trois points.</dc:description>"
        "<cp:lastModifiedBy>Codex</cp:lastModifiedBy>"
        f"<dcterms:created xsi:type=\"dcterms:W3CDTF\">{now}</dcterms:created>"
        f"<dcterms:modified xsi:type=\"dcterms:W3CDTF\">{now}</dcterms:modified>"
        "</cp:coreProperties>"
    )
    app = (
        '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        '<Properties xmlns="http://schemas.openxmlformats.org/officeDocument/2006/extended-properties" '
        'xmlns:vt="http://schemas.openxmlformats.org/officeDocument/2006/docPropsVTypes">'
        "<Application>Microsoft Office Word</Application>"
        "</Properties>"
    )
    zipf.writestr("docProps/core.xml", core)
    zipf.writestr("docProps/app.xml", app)


def main() -> None:
    if not TEMPLATE.exists():
        raise SystemExit(f"Template introuvable: {TEMPLATE}")

    if OUTPUT.exists():
        OUTPUT.unlink()

    with tempfile.NamedTemporaryFile(delete=False, suffix=".docx") as tmp:
        tmp_path = Path(tmp.name)

    try:
        with ZipFile(TEMPLATE, "r") as src, ZipFile(tmp_path, "w", compression=ZIP_DEFLATED) as dst:
            for item in src.infolist():
                if item.filename in {"word/document.xml", "docProps/core.xml", "docProps/app.xml"}:
                    continue
                dst.writestr(item, src.read(item.filename))

            dst.writestr("word/document.xml", build_document_xml())
            write_metadata(dst)

        shutil.move(tmp_path, OUTPUT)
    finally:
        if tmp_path.exists():
            tmp_path.unlink()


if __name__ == "__main__":
    main()
