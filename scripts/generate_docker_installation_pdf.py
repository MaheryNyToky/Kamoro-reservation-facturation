from __future__ import annotations

import math
from pathlib import Path

import generate_installation_pdf as base


ROOT = Path(__file__).resolve().parents[1]
OUTPUT = ROOT / "Guide_installation_Docker_Kamoro.pdf"
GIT_URL = "https://github.com/MaheryNyToky/Kamoro-reservation-facturation.git"


def build_lines() -> list[tuple[str, int, str]]:
    items: list[tuple[str, int, str]] = []

    def title(text: str) -> None:
        items.append((text, 18, "bold"))
        items.append(("", 10, "regular"))

    def h1(text: str) -> None:
        items.append(("", 10, "regular"))
        items.append((text, 14, "bold"))

    def h2(text: str) -> None:
        items.append(("", 8, "regular"))
        items.append((text, 12, "bold"))

    def p(text: str) -> None:
        for line in base.wrap_paragraph(text):
            items.append((line, 10, "regular"))

    def bullet(text: str) -> None:
        wrapped = base.wrap_paragraph(text, 84)
        if wrapped:
            items.append((f"- {wrapped[0]}", 10, "regular"))
            for line in wrapped[1:]:
                items.append((f"  {line}", 10, "regular"))

    def cmd(text: str) -> None:
        for line in base.wrap_paragraph(text, 82):
            items.append((f"    {line}", 9, "regular"))

    title("Installation Docker - Kamoro Reservation Facturation")
    p("Ce guide explique comment installer l'application Kamoro sur un PC Windows avec Docker. Il est ecrit pour une personne tres debutante.")
    p("Avec Docker, il n'est plus necessaire d'installer PHP, Composer, Python ou Flutter a la main. Docker prepare tout dans des boites separees.")
    p(f"Lien GitHub du projet : {GIT_URL}")
    p("A la fin, l'application s'ouvrira dans Chrome a cette adresse : http://127.0.0.1:8080/index.html")

    h1("1. Ce qu'il faut comprendre")
    bullet("Docker Desktop est le programme principal a installer.")
    bullet("Le projet contient deja les fichiers Docker necessaires.")
    bullet("Le premier lancement peut etre long, parfois 10 a 30 minutes, car Docker telecharge et construit l'application.")
    bullet("Les fois suivantes, le lancement sera beaucoup plus rapide.")
    bullet("Il faut garder Docker Desktop ouvert pendant l'utilisation de l'application.")

    h1("2. Installer Google Chrome")
    p("Chrome servira a ouvrir l'application.")
    bullet("Ouvre Microsoft Edge ou ton navigateur actuel.")
    bullet("Va sur : https://www.google.com/chrome/")
    bullet("Clique sur Telecharger Chrome.")
    bullet("Ouvre le fichier telecharge.")
    bullet("Clique sur Oui si Windows demande l'autorisation.")
    bullet("Attends la fin de l'installation.")
    p("C'est bon si Google Chrome apparait dans le menu Demarrer.")

    h1("3. Installer Git")
    p("Git sert a telecharger le projet depuis GitHub.")
    bullet("Va sur : https://git-scm.com/download/win")
    bullet("Ouvre le fichier Git telecharge.")
    bullet("Pendant l'installation, clique sur Next a chaque ecran.")
    bullet("A la fin, clique sur Install, puis Finish.")
    p("Verification simple :")
    bullet("Ouvre PowerShell.")
    bullet("Tape cette commande :")
    cmd("git --version")
    p("C'est bon si une version s'affiche, par exemple git version 2.x.")

    h1("4. Installer Docker Desktop")
    p("Docker Desktop est l'outil qui va faire tourner l'application sans installer tous les outils techniques un par un.")
    bullet("Va sur : https://www.docker.com/products/docker-desktop/")
    bullet("Clique sur Download for Windows.")
    bullet("Ouvre le fichier Docker Desktop Installer telecharge.")
    bullet("Si Windows demande l'autorisation, clique sur Oui.")
    bullet("Pendant l'installation, garde les options proposees par defaut.")
    bullet("Clique sur OK ou Install quand Docker le demande.")
    bullet("A la fin, Docker peut demander de redemarrer l'ordinateur. Clique sur Restart ou redemarre toi-meme.")

    h2("Premier demarrage de Docker")
    bullet("Apres le redemarrage, ouvre Docker Desktop depuis le menu Demarrer.")
    bullet("Accepte les conditions si Docker les affiche.")
    bullet("Docker peut demander d'activer WSL 2. Accepte ce qu'il propose.")
    bullet("Attends que Docker Desktop soit ouvert et stable.")
    bullet("En bas a gauche ou en bas de la fenetre, Docker doit indiquer qu'il est pret ou running.")
    p("Important : si Docker Desktop n'est pas ouvert, l'application Kamoro ne pourra pas demarrer.")

    h2("Verifier Docker")
    bullet("Ouvre PowerShell.")
    bullet("Tape cette commande :")
    cmd("docker --version")
    p("C'est bon si une version Docker s'affiche.")
    bullet("Tape ensuite :")
    cmd("docker compose version")
    p("C'est bon si une version Docker Compose s'affiche.")

    h1("5. Telecharger le projet Kamoro")
    p("On va mettre le projet sur le Bureau pour le retrouver facilement.")
    bullet("Ouvre PowerShell.")
    bullet("Va sur le Bureau :")
    cmd("cd Desktop")
    bullet("Telecharge le projet :")
    cmd(f"git clone {GIT_URL}")
    bullet("Entre dans le dossier du projet :")
    cmd("cd Kamoro-reservation-facturation")
    p("C'est bon si PowerShell est dans le dossier Kamoro-reservation-facturation.")

    h1("6. Creer les icones sur le Bureau")
    p("Cette etape cree deux icones faciles a utiliser : une pour lancer l'application, une pour l'arreter.")
    bullet("Dans PowerShell, verifie que tu es dans le dossier Kamoro-reservation-facturation.")
    bullet("Tape cette commande :")
    cmd("powershell -ExecutionPolicy Bypass -File .\\Creer-raccourcis-docker-bureau.ps1")
    bullet("Retourne sur le Bureau Windows.")
    bullet("Tu dois voir deux nouvelles icones : Kamoro - Lancer et Kamoro - Arreter.")
    p("Si les icones ne se creent pas, ce n'est pas grave. Tu peux lancer l'application directement avec le fichier Lancer-Kamoro-Docker.bat dans le dossier du projet.")

    h1("7. Lancer l'application")
    h2("Methode la plus simple")
    bullet("Double-clique sur l'icone Kamoro - Lancer sur le Bureau.")
    bullet("Une fenetre noire va s'ouvrir.")
    bullet("Le premier lancement peut etre tres long. C'est normal.")
    bullet("Docker va construire trois parties : le backend Laravel, le moteur IA, et l'interface Flutter.")
    bullet("Quand c'est termine, Chrome doit s'ouvrir automatiquement.")
    bullet("Si Chrome ne s'ouvre pas tout seul, ouvre Chrome et va sur :")
    cmd("http://127.0.0.1:8080/index.html")

    h2("Methode de secours")
    bullet("Ouvre le dossier Kamoro-reservation-facturation.")
    bullet("Double-clique sur Lancer-Kamoro-Docker.bat.")
    bullet("Attends la fin du lancement.")

    h1("8. Se connecter")
    bullet("Compte administrateur : admin@kamorohotel.com")
    bullet("Mot de passe administrateur : admin123")
    bullet("Compte reception : reco1@kamorohotel.com")
    bullet("Mot de passe reception : reco123")

    h1("9. Donnees de reservation deja incluses")
    p("La base de donnees database.sqlite est incluse dans le projet. Elle contient deja des chambres et des reservations pour tester.")
    h2("Reservations futures a tester")
    bullet("TEST-NOELY-009 - Noely Razafindrakoto - du 2026-06-28 au 2026-06-30 - statut en attente - chambres 01 et 02.")
    bullet("TEST-ANITA-004 - Anita Rakotonirina - du 2026-06-23 au 2026-06-25 - statut en attente - chambres 04, 101 et 01.")
    bullet("RES-74501A - M N - du 2026-06-22 au 2026-06-25 - statut arrive - chambre 104.")
    h2("Reservations arrivees ou payees a tester")
    bullet("RES-1F46C0 - Tojo Randriamampionona - du 2026-06-19 au 2026-06-20 - statut arrive - chambres 03 et 02.")
    bullet("TEST-FENITRA-006 - Fenitra Rabe - du 2026-06-18 au 2026-06-20 - statut arrive et paye.")
    h2("Test Booking")
    bullet("Cree une nouvelle reservation.")
    bullet("Active Reservation via Booking.com.")
    bullet("Choisis une Chambre Double Superieure, par exemple 101, 105, 106, 201 ou 202 si elle est libre.")
    bullet("Le prix doit etre 162 500 Ar par chambre.")
    bullet("Apres check-in, va dans Folio et facturation et active Facture Booking en euro.")
    bullet("Le PDF en euro doit afficher 32,50 EUR par chambre, 10 EUR pour lit supplementaire et 6 EUR pour matelas.")

    h1("10. Arreter l'application")
    bullet("Quand tu as fini, double-clique sur l'icone Kamoro - Arreter sur le Bureau.")
    bullet("Si tu n'as pas l'icone, ouvre le dossier du projet et double-clique sur Arreter-Kamoro-Docker.bat.")
    bullet("Cela arrete les boites Docker de l'application.")
    p("Ne supprime pas Docker Desktop et ne supprime pas le dossier du projet, sinon l'application ne sera plus disponible.")

    h1("11. Les prochaines fois")
    bullet("Ouvre Docker Desktop.")
    bullet("Attends que Docker soit pret.")
    bullet("Double-clique sur Kamoro - Lancer.")
    bullet("Ouvre l'application dans Chrome si elle ne s'ouvre pas toute seule : http://127.0.0.1:8080/index.html")
    bullet("Quand tu as fini, double-clique sur Kamoro - Arreter.")

    h1("12. Probleme : Docker n'est pas ouvert")
    bullet("Ouvre Docker Desktop depuis le menu Demarrer.")
    bullet("Attends quelques minutes.")
    bullet("Relance Kamoro - Lancer.")

    h1("13. Probleme : Docker demande WSL 2")
    bullet("Accepte l'installation de WSL 2 si Docker la propose.")
    bullet("Redemarre l'ordinateur si Docker le demande.")
    bullet("Ouvre Docker Desktop apres le redemarrage.")
    bullet("Relance Kamoro - Lancer.")

    h1("14. Probleme : le premier lancement est tres long")
    bullet("C'est normal au premier lancement.")
    bullet("Docker telecharge PHP, Python, Flutter et construit l'application.")
    bullet("Il faut laisser l'ordinateur travailler.")
    bullet("Si internet est lent, cela peut prendre plus de 30 minutes.")

    h1("15. Probleme : le navigateur affiche une erreur")
    bullet("Attends encore une ou deux minutes.")
    bullet("Recharge la page Chrome.")
    bullet("Verifie que l'adresse est exactement : http://127.0.0.1:8080/index.html")
    bullet("Verifie que Docker Desktop est ouvert.")
    bullet("Si besoin, double-clique sur Kamoro - Arreter puis sur Kamoro - Lancer.")

    h1("16. Probleme : les ports sont deja utilises")
    p("L'application utilise les ports 8000, 8001 et 8080. Si une autre application utilise ces ports, Kamoro peut ne pas demarrer.")
    bullet("Ferme les anciennes fenetres de lancement Kamoro.")
    bullet("Double-clique sur Kamoro - Arreter.")
    bullet("Puis double-clique sur Kamoro - Lancer.")

    h1("17. Ce qu'il faut envoyer a la personne")
    bullet("Envoyer le lien GitHub : " + GIT_URL)
    bullet("Envoyer ce PDF.")
    bullet("Dire a la personne de suivre les etapes dans l'ordre.")
    bullet("Dire que le premier lancement est long, mais que c'est normal.")

    h1("18. Resume tres simple")
    bullet("Installer Chrome.")
    bullet("Installer Git.")
    bullet("Installer Docker Desktop.")
    bullet("Redemarrer l'ordinateur.")
    bullet("Telecharger le projet avec git clone.")
    bullet("Creer les icones Bureau.")
    bullet("Double-cliquer sur Kamoro - Lancer.")
    bullet("Se connecter avec admin@kamorohotel.com / admin123.")

    return items


def main() -> None:
    writer = base.PdfWriter()
    pages = base.paginate(build_lines())
    for page_index, page in enumerate(pages, start=1):
        positions = []
        y = 738
        for _, size, _ in page:
            positions.append(y)
            y -= max(10, math.ceil(size * 1.45))
        page_with_footer = page + [(f"Page {page_index} / {len(pages)}", 8, "regular")]
        positions.append(36)
        base.text_positions = positions
        writer.add_page(page_with_footer)
    writer.write(OUTPUT)
    print(OUTPUT)


if __name__ == "__main__":
    main()
