from __future__ import annotations

import math
import textwrap
from pathlib import Path


ROOT = Path(__file__).resolve().parents[1]
OUTPUT = ROOT / "Guide_installation_Kamoro_HestiaPredict.pdf"
GIT_URL = "https://github.com/MaheryNyToky/Kamoro-reservation-facturation.git"


def clean(text: str) -> str:
    replacements = {
        "\u2019": "'",
        "\u2018": "'",
        "\u201c": '"',
        "\u201d": '"',
        "\u2013": "-",
        "\u2014": "-",
        "\u2026": "...",
        "\u00a0": " ",
        "\u2192": "->",
        "\u20ac": "EUR",
    }
    for src, dst in replacements.items():
        text = text.replace(src, dst)
    return text


def pdf_escape(text: str) -> str:
    text = clean(text)
    return (
        text.replace("\\", "\\\\")
        .replace("(", "\\(")
        .replace(")", "\\)")
        .encode("cp1252", "replace")
        .decode("cp1252")
    )


class PdfWriter:
    def __init__(self) -> None:
        self.objects: list[bytes] = []
        self.pages: list[int] = []
        self.add_object("<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>")
        self.add_object("<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold >>")

    def add_object(self, body: str | bytes) -> int:
        if isinstance(body, str):
            data = body.encode("latin-1", "replace")
        else:
            data = body
        self.objects.append(data)
        return len(self.objects)

    def add_page(self, lines: list[tuple[str, int, str]]) -> None:
        content = ["BT"]
        for text, size, style in lines:
            font = "/F2" if style == "bold" else "/F1"
            x = 54
            y = text_positions.pop(0)
            content.append(f"{font} {size} Tf")
            content.append(f"1 0 0 1 {x} {y} Tm")
            content.append(f"({pdf_escape(text)}) Tj")
        content.append("ET")
        stream = "\n".join(content).encode("latin-1", "replace")
        stream_obj = self.add_object(
            b"<< /Length " + str(len(stream)).encode("ascii") + b" >>\nstream\n" + stream + b"\nendstream"
        )
        page_obj = self.add_object(
            f"<< /Type /Page /Parent 0 0 R /MediaBox [0 0 612 792] "
            f"/Resources << /Font << /F1 1 0 R /F2 2 0 R >> >> "
            f"/Contents {stream_obj} 0 R >>"
        )
        self.pages.append(page_obj)

    def write(self, path: Path) -> None:
        pages_kids = " ".join(f"{page} 0 R" for page in self.pages)
        pages_obj = self.add_object(f"<< /Type /Pages /Kids [{pages_kids}] /Count {len(self.pages)} >>")
        for page_num in self.pages:
            page_body = self.objects[page_num - 1].decode("latin-1")
            page_body = page_body.replace("/Parent 0 0 R", f"/Parent {pages_obj} 0 R")
            self.objects[page_num - 1] = page_body.encode("latin-1")
        catalog_obj = self.add_object(f"<< /Type /Catalog /Pages {pages_obj} 0 R >>")

        output = bytearray(b"%PDF-1.4\n%\xe2\xe3\xcf\xd3\n")
        offsets = [0]
        for index, obj in enumerate(self.objects, start=1):
            offsets.append(len(output))
            output.extend(f"{index} 0 obj\n".encode("ascii"))
            output.extend(obj)
            output.extend(b"\nendobj\n")
        xref_pos = len(output)
        output.extend(f"xref\n0 {len(self.objects) + 1}\n".encode("ascii"))
        output.extend(b"0000000000 65535 f \n")
        for offset in offsets[1:]:
            output.extend(f"{offset:010d} 00000 n \n".encode("ascii"))
        output.extend(
            f"trailer\n<< /Size {len(self.objects) + 1} /Root {catalog_obj} 0 R >>\n"
            f"startxref\n{xref_pos}\n%%EOF\n".encode("ascii")
        )
        path.write_bytes(output)


def wrap_paragraph(text: str, width: int = 88) -> list[str]:
    text = clean(text).strip()
    if not text:
        return [""]
    return textwrap.wrap(text, width=width, break_long_words=False, replace_whitespace=False)


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
        for line in wrap_paragraph(text):
            items.append((line, 10, "regular"))

    def bullet(text: str) -> None:
        wrapped = wrap_paragraph(text, 84)
        if wrapped:
            items.append((f"- {wrapped[0]}", 10, "regular"))
            for line in wrapped[1:]:
                items.append((f"  {line}", 10, "regular"))

    def cmd(text: str) -> None:
        for line in wrap_paragraph(text, 82):
            items.append((f"    {line}", 9, "regular"))

    title("Guide d'installation - Kamoro Reservation Facturation")
    p("Ce document explique comment installer et lancer l'application sur un nouveau PC Windows. Il est ecrit pour une personne qui n'a presque jamais installe de projet informatique.")
    p(f"Lien du projet GitHub a utiliser : {GIT_URL}")
    p("A la fin, la personne pourra ouvrir l'application dans Google Chrome a cette adresse : http://127.0.0.1:8080/index.html")

    h1("1. Avant de commencer")
    bullet("Il faut un ordinateur Windows avec internet.")
    bullet("Il faut environ 30 a 60 minutes pour la premiere installation.")
    bullet("Il faut garder PowerShell ouvert pendant que l'application tourne.")
    bullet("Si Windows demande une autorisation, cliquer sur Oui ou Autoriser.")
    bullet("Ne ferme pas la fenetre noire/bleue de PowerShell quand l'application est lancee.")

    h1("2. Installer Google Chrome")
    p("Chrome servira a ouvrir l'application.")
    bullet("Ouvre ton navigateur actuel, par exemple Microsoft Edge.")
    bullet("Va sur : https://www.google.com/chrome/")
    bullet("Clique sur Telecharger Chrome.")
    bullet("Ouvre le fichier telecharge.")
    bullet("Clique sur Oui si Windows demande l'autorisation.")
    bullet("Attends la fin de l'installation.")
    p("C'est bon si tu vois Google Chrome dans le menu Demarrer.")

    h1("3. Installer Git")
    p("Git sert a telecharger le projet depuis GitHub.")
    bullet("Va sur : https://git-scm.com/download/win")
    bullet("Le telechargement devrait commencer automatiquement.")
    bullet("Ouvre le fichier Git telecharge.")
    bullet("Pendant l'installation, clique sur Next a chaque etape.")
    bullet("A la fin, clique sur Install, puis Finish.")
    p("Pour verifier, ouvre PowerShell et tape :")
    cmd("git --version")
    p("C'est bon si tu vois une ligne qui commence par git version.")

    h1("4. Installer PHP avec XAMPP")
    p("PHP est necessaire pour lancer le backend Laravel. Le plus simple pour une personne debutante est XAMPP.")
    bullet("Va sur : https://www.apachefriends.org/fr/index.html")
    bullet("Clique sur XAMPP pour Windows.")
    bullet("Ouvre le fichier telecharge.")
    bullet("Si Windows affiche un avertissement, clique sur Oui.")
    bullet("Quand XAMPP demande les composants, garde les choix par defaut. C'est plus simple.")
    bullet("Clique sur Next jusqu'a Install.")
    bullet("Quand c'est termine, clique sur Finish.")
    h2("Ajouter PHP au PATH Windows")
    p("Cette petite etape permet d'utiliser la commande php dans PowerShell.")
    bullet("Ouvre le menu Demarrer.")
    bullet("Tape : variables d'environnement")
    bullet("Clique sur Modifier les variables d'environnement systeme.")
    bullet("Clique sur le bouton Variables d'environnement.")
    bullet("Dans la zone Variables systeme, clique sur Path, puis Modifier.")
    bullet("Clique sur Nouveau.")
    bullet("Ecris exactement : C:\\xampp\\php")
    bullet("Clique sur OK, encore OK, encore OK.")
    bullet("Ferme PowerShell s'il est deja ouvert, puis rouvre PowerShell.")
    p("Pour verifier, tape :")
    cmd("php -v")
    p("C'est bon si tu vois PHP avec un numero de version, par exemple PHP 8.x.")

    h1("5. Installer Composer")
    p("Composer installe les dependances du backend Laravel.")
    bullet("Va sur : https://getcomposer.org/download/")
    bullet("Clique sur Composer-Setup.exe.")
    bullet("Ouvre le fichier telecharge.")
    bullet("Si Composer demande ou est PHP, choisis : C:\\xampp\\php\\php.exe")
    bullet("Clique sur Next jusqu'a Install.")
    bullet("Ferme PowerShell puis rouvre PowerShell.")
    p("Pour verifier, tape :")
    cmd("composer --version")
    p("C'est bon si tu vois Composer avec un numero de version.")

    h1("6. Installer Python")
    p("Python sert a lancer le petit moteur d'intelligence artificielle.")
    bullet("Va sur : https://www.python.org/downloads/windows/")
    bullet("Clique sur Download Python.")
    bullet("Ouvre le fichier telecharge.")
    bullet("Tres important : coche la case Add Python to PATH avant de cliquer.")
    bullet("Clique sur Install Now.")
    bullet("Quand c'est termine, clique sur Close.")
    bullet("Ferme PowerShell puis rouvre PowerShell.")
    p("Pour verifier, tape :")
    cmd("python --version")
    p("C'est bon si tu vois Python 3.11 ou plus recent.")

    h1("7. Installer Flutter")
    p("Flutter sert a construire l'interface de l'application.")
    h2("Telecharger Flutter")
    bullet("Va sur : https://docs.flutter.dev/get-started/install/windows")
    bullet("Cherche le bouton ou lien pour telecharger Flutter SDK pour Windows.")
    bullet("Telecharge le fichier ZIP.")
    bullet("Va dans le dossier Telechargements.")
    bullet("Clique droit sur le ZIP, puis Extraire tout.")
    bullet("Mets Flutter dans un dossier simple, par exemple : C:\\src\\flutter")
    h2("Ajouter Flutter au PATH Windows")
    bullet("Ouvre le menu Demarrer.")
    bullet("Tape : variables d'environnement")
    bullet("Clique sur Modifier les variables d'environnement systeme.")
    bullet("Clique sur Variables d'environnement.")
    bullet("Dans Variables systeme, clique sur Path, puis Modifier.")
    bullet("Clique sur Nouveau.")
    bullet("Ecris exactement : C:\\src\\flutter\\bin")
    bullet("Clique sur OK, encore OK, encore OK.")
    bullet("Ferme PowerShell puis rouvre PowerShell.")
    p("Pour verifier, tape :")
    cmd("flutter --version")
    p("C'est bon si tu vois Flutter avec un numero de version.")
    p("Si Flutter demande d'installer quelque chose en plus, suis les instructions affichees. Pour cette application web locale, Chrome suffit normalement.")

    h1("8. Telecharger le projet Kamoro")
    p("Maintenant on recupere l'application depuis GitHub.")
    bullet("Ouvre PowerShell.")
    bullet("Va sur le Bureau avec cette commande :")
    cmd("cd Desktop")
    bullet("Telecharge le projet avec cette commande :")
    cmd(f"git clone {GIT_URL}")
    bullet("Entre dans le dossier du projet :")
    cmd("cd Kamoro-reservation-facturation")
    p("C'est bon si PowerShell est maintenant dans le dossier Kamoro-reservation-facturation.")

    h1("9. Installer le backend Laravel")
    p("Copie-colle ces commandes une par une dans PowerShell. Attends que chaque commande termine avant de mettre la suivante.")
    cmd("cd hestiapredict")
    cmd("composer install")
    cmd("copy .env.example .env")
    cmd("php artisan key:generate")
    cmd("cd ..")
    p("C'est bon si aucune ligne rouge d'erreur ne reste a la fin.")

    h1("10. Installer le moteur IA")
    p("Copie-colle ces commandes une par une.")
    cmd("cd hestia-ai")
    cmd("python -m venv venv")
    cmd(".\\venv\\Scripts\\python.exe -m pip install --upgrade pip")
    cmd(".\\venv\\Scripts\\python.exe -m pip install fastapi uvicorn pandas prophet")
    cmd("cd ..")
    p("Cette etape peut etre longue. C'est normal si l'ordinateur travaille plusieurs minutes.")

    h1("11. Installer l'interface Flutter")
    p("Copie-colle ces commandes une par une.")
    cmd("cd hestia_app")
    cmd("flutter pub get")
    cmd("cd ..")
    p("C'est bon si tu reviens dans le dossier principal du projet.")

    h1("12. Lancer l'application")
    p("Tu dois etre dans le dossier principal Kamoro-reservation-facturation. Lance cette commande :")
    cmd(".\\dev.ps1")
    p("Si Windows bloque le script PowerShell, tape cette commande puis recommence :")
    cmd("Set-ExecutionPolicy -Scope CurrentUser RemoteSigned")
    p("Quand PowerShell demande confirmation, tape O puis appuie sur Entree.")
    p("Ensuite relance :")
    cmd(".\\dev.ps1")
    p("Attends. Le premier lancement peut etre long, car Flutter construit l'application.")
    p("Quand tu vois Environnement pret, ouvre Chrome et va sur :")
    cmd("http://127.0.0.1:8080/index.html")

    h1("13. Mettre l'application sur le Bureau")
    p("Cette etape sert a avoir une icone sur le Bureau. Apres ca, la personne n'aura plus besoin de taper les commandes a chaque fois.")
    h2("Creer automatiquement le raccourci")
    bullet("Ouvre PowerShell.")
    bullet("Va dans le dossier du projet. Si le projet est sur le Bureau, tape :")
    cmd("cd Desktop\\Kamoro-reservation-facturation")
    bullet("Tape cette commande :")
    cmd("powershell -ExecutionPolicy Bypass -File .\\Creer-raccourci-bureau.ps1")
    bullet("Regarde ton Bureau Windows.")
    bullet("Tu dois voir une nouvelle icone : Kamoro Reservation Facturation.")
    h2("Utiliser l'application les prochaines fois")
    bullet("Double-clique sur l'icone Kamoro Reservation Facturation sur le Bureau.")
    bullet("Une fenetre va s'ouvrir et lancer les services.")
    bullet("Attends que l'application s'ouvre dans Chrome.")
    bullet("Ne ferme pas la fenetre de lancement pendant que tu utilises l'application.")
    h2("Si le raccourci ne se cree pas")
    bullet("Va dans le dossier Kamoro-reservation-facturation.")
    bullet("Double-clique directement sur le fichier Lancer-Kamoro.bat.")
    bullet("L'application se lancera aussi de cette maniere.")

    h1("14. Comptes pour se connecter")
    bullet("Compte administrateur : admin@kamorohotel.com")
    bullet("Mot de passe administrateur : admin123")
    bullet("Compte reception : reco1@kamorohotel.com")
    bullet("Mot de passe reception : reco123")

    h1("15. Les prochaines fois")
    p("La prochaine fois, il ne faut plus tout installer. Il faut seulement lancer l'application.")
    bullet("Methode simple : double-clique sur l'icone Kamoro Reservation Facturation sur le Bureau.")
    bullet("Methode de secours : va dans le dossier du projet et double-clique sur Lancer-Kamoro.bat.")
    bullet("Methode PowerShell : tape cd Desktop\\Kamoro-reservation-facturation puis .\\dev.ps1.")
    bullet("Le lien de l'application reste : http://127.0.0.1:8080/index.html")

    h1("16. Donnees de reservation deja presentes pour tester")
    p("La base de donnees fournie avec le projet contient deja des chambres et des reservations. Cela permet de tester l'application sans tout creer a la main.")
    h2("Reservations futures a tester")
    bullet("TEST-NOELY-009 - Noely Razafindrakoto - du 2026-06-28 au 2026-06-30 - statut en attente - chambres 01 et 02.")
    bullet("TEST-ANITA-004 - Anita Rakotonirina - du 2026-06-23 au 2026-06-25 - statut en attente - chambres 04, 101 et 01.")
    bullet("RES-74501A - M N - du 2026-06-22 au 2026-06-25 - statut arrive - chambre 104.")
    h2("Reservations arrivees ou payees a tester")
    bullet("RES-1F46C0 - Tojo Randriamampionona - du 2026-06-19 au 2026-06-20 - statut arrive - chambres 03 et 02.")
    bullet("TEST-FENITRA-006 - Fenitra Rabe - du 2026-06-18 au 2026-06-20 - statut arrive et paye.")
    h2("Reservations annulees a verifier")
    bullet("RES-CD6FD0 - test - du 2026-06-18 au 2026-06-19 - statut annule.")
    bullet("RES-DF929A - Mahery Rakotovao - du 2026-06-18 au 2026-06-19 - statut annule.")
    h2("Chambres utiles pour les tests")
    bullet("Chambre 02 : Chambre Double Standard etat degrade, prix fixe 95 000 Ar.")
    bullet("Chambres 101, 105, 106, 201 et 202 : Chambre Double Superieure, prix 125 000 Ar.")
    bullet("Chambre 104 : Chambre Familiale Superieure, prix 205 000 Ar.")
    bullet("Chambre 12 ou 15 : Chambre Twin Standard, prix 100 000 Ar.")
    h2("Test special Booking")
    p("Pour tester la nouvelle regle Booking, cree une nouvelle reservation depuis l'application.")
    bullet("Active le bouton Reservation via Booking.com.")
    bullet("Choisis uniquement une Chambre Double Superieure, par exemple 101, 105, 106, 201 ou 202 si elle est libre.")
    bullet("Le prix de la chambre doit etre 162 500 Ar, meme si le prix fixe ou IA affiche autre chose.")
    bullet("Apres le check-in, va dans Folio et facturation, puis active Facture Booking en euro.")
    bullet("Dans le PDF en euro, la chambre doit afficher 32,50 EUR. Le lit supplementaire doit afficher 10 EUR et le matelas 6 EUR.")

    h1("17. Probleme frequent : php n'est pas reconnu")
    p("Si PowerShell dit que php n'est pas reconnu, cela veut dire que PHP n'est pas bien ajoute au PATH.")
    bullet("Retourne a l'etape 4.")
    bullet("Verifie que tu as bien ajoute : C:\\xampp\\php")
    bullet("Ferme PowerShell puis rouvre PowerShell.")
    bullet("Retape : php -v")

    h1("18. Probleme frequent : composer n'est pas reconnu")
    bullet("Reinstalle Composer.")
    bullet("Pendant l'installation, choisis bien : C:\\xampp\\php\\php.exe")
    bullet("Ferme PowerShell puis rouvre PowerShell.")
    bullet("Retape : composer --version")

    h1("19. Probleme frequent : python n'est pas reconnu")
    bullet("Reinstalle Python.")
    bullet("Coche bien Add Python to PATH au debut de l'installation.")
    bullet("Ferme PowerShell puis rouvre PowerShell.")
    bullet("Retape : python --version")

    h1("20. Probleme frequent : flutter n'est pas reconnu")
    bullet("Verifie que Flutter est bien dans : C:\\src\\flutter")
    bullet("Verifie que le PATH contient : C:\\src\\flutter\\bin")
    bullet("Ferme PowerShell puis rouvre PowerShell.")
    bullet("Retape : flutter --version")

    h1("21. Probleme frequent : l'application ne s'ouvre pas")
    bullet("Verifie que PowerShell affiche Environnement pret.")
    bullet("Verifie que tu as ouvert exactement : http://127.0.0.1:8080/index.html")
    bullet("Ne ferme pas PowerShell pendant que tu utilises l'application.")
    bullet("Si un message parle du port 8000, 8001 ou 8080, ferme les anciennes fenetres PowerShell et recommence.")

    h1("22. Probleme frequent : le raccourci Bureau ne marche pas")
    bullet("Verifie que le dossier Kamoro-reservation-facturation n'a pas ete deplace.")
    bullet("Si le dossier a ete deplace, relance : powershell -ExecutionPolicy Bypass -File .\\Creer-raccourci-bureau.ps1")
    bullet("Si Windows bloque le fichier, clique sur Informations complementaires puis Executer quand meme.")
    bullet("Tu peux toujours lancer l'application en double-cliquant sur Lancer-Kamoro.bat dans le dossier du projet.")

    h1("23. A retenir")
    bullet("La premiere installation est longue.")
    bullet("Les fois suivantes, il suffit de double-cliquer sur l'icone du Bureau.")
    bullet("L'application fonctionne en local sur l'ordinateur.")
    bullet("Le lien a ouvrir est toujours : http://127.0.0.1:8080/index.html")

    return items


def paginate(items: list[tuple[str, int, str]]) -> list[list[tuple[str, int, str]]]:
    pages: list[list[tuple[str, int, str]]] = []
    page: list[tuple[str, int, str]] = []
    current_height = 0
    max_height = 690

    for text, size, style in items:
        line_height = max(10, math.ceil(size * 1.45))
        if current_height + line_height > max_height and page:
            pages.append(page)
            page = []
            current_height = 0
        page.append((text, size, style))
        current_height += line_height
    if page:
        pages.append(page)
    return pages


def main() -> None:
    global text_positions
    writer = PdfWriter()
    pages = paginate(build_lines())
    for page_index, page in enumerate(pages, start=1):
        text_positions = []
        y = 738
        for _, size, _ in page:
            text_positions.append(y)
            y -= max(10, math.ceil(size * 1.45))
        page_with_footer = page + [(f"Page {page_index} / {len(pages)}", 8, "regular")]
        text_positions.append(36)
        writer.add_page(page_with_footer)
    writer.write(OUTPUT)
    print(OUTPUT)


if __name__ == "__main__":
    main()
