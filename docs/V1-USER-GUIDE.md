# WP Seed Pixel 0.4.0 - Guide d'administration

## Installation

Sauvegarder la base, les medias et le plugin installe. Installer le ZIP approuve
depuis Extensions > Ajouter une extension > Televerser une extension.
Une mise a jour remplace le plugin : ne pas le desinstaller d'abord.
L'activation ne traite aucun ancien media et n'active pas un nouveau format.

## Reglages Principaux

Ouvrir Medias > WP Seed Pixel. La page normale contient l'optimisation
automatique, les images existantes selectionnees et l'activite recente.
Activer JPEG et/ou PNG uniquement pour les futurs envois souhaites. PNG reste
sans perte et conserve son format. Les anciens medias ne sont pas traites
automatiquement ; changer ce choix etablit une nouvelle baseline d'envois.

La recuperation privee est preparee automatiquement sur un hebergement
compatible. Aucun chemin FTP/SSH ni budget MiB manuel obligatoire.
Si les preuves de securite du stockage manquent, Pixel refuse le traitement
et conserve le media. Le plafond global facultatif appartient aux reglages
avances et ne remplace pas le controle de l'espace physique.

## Optimiser Une Image

Dans la mediatheque, ouvrir l'image puis Optimiser cette image.
Le panneau indique son format, ses dimensions et son poids. Ne pas relancer
un traitement sans raison : une image peut deja ne presenter aucun gain utile.
Ce resultat est normal ; l'image actuelle reste intacte. La copie temporaire
inutile est nettoyee si Pixel prouve qu'aucun remplacement n'a eu lieu.
Un petit historique technique peut rester, sans copie de recuperation inutile.

## Optimiser Des Images Selectionnees

Dans la page Pixel, ouvrir le selecteur WordPress, choisir les images
(maximum 500), verifier le nombre selectionne et lancer cette selection.
Aucun media hors selection n'est traite. Les jobs persistent ; utiliser
Pause/Reprendre si necessaire. Lancer un traitement de toute la mediatheque
reste une action avancee distincte et explicite.

## Original, Version Optimisee Et Gain

Apres remplacement, le panneau distingue les dimensions et poids de l'original
et de la version optimisee, puis le gain de l'image active.
Conserve pour restauration signifie que l'original occupe encore du disque.
Le gain actif ne constitue donc pas encore de l'espace definitivement libere.
Aucun gain utile n'est pas une erreur et ne propose pas de faux bouton Restaurer.

## Restaurer Ou Supprimer L'Original

Restaurer l'original verifie la generation courante et remet ses octets exacts.
Supprimer definitivement l'original requiert une confirmation separee pour
cette version. Cette action est irreversible et retire la possibilite de
restauration. Une recuperation necessaire n'expire pas automatiquement.
Une situation ambigue conserve les preuves et les octets pour controle.

## Mises A Jour

Lorsqu'une adresse HTTPS de distribution approuvee par le proprietaire est
configuree, utiliser Extensions > WP Seed Pixel > Mettre a jour maintenant.
Le plugin controle version, compatibilite et SHA-256 avant remplacement.
Un echec reseau ou d'integrite bloque la mise a jour ; aucun media n'est traite.

Sans cette adresse, installer manuellement le ZIP approuve et verifier son
SHA-256. Sauvegarder le runtime et les reglages avant remplacement. Une mise a
jour conserve les choix JPEG/PNG, les anciens medias, les jobs et recuperations.

## Limites Et Suppression Du Plugin

Les images restent locales, sans cloud ni telemetrie. JPEG reste JPEG, PNG
reste PNG ; aucune conversion ordinaire WebP/AVIF.
Les limites PNG et les hebergements certifies sont documentes separement.
Pixel ne remplace pas la protection d'acces d'un album prive.

Desactiver le plugin arrete l'automatisation mais laisse des medias WordPress
valides. La desinstallation ne supprime pas silencieusement les originaux de
recuperation. Reinstaller le runtime approuve pour retrouver ces commandes.
Conserver une sauvegarde d'hebergement pour la recuperation apres sinistre.
Les details techniques restent replies dans l'interface normale.
