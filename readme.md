## 1) Prérequis

* Ubuntu/Debian avec :

  * `nginx`
  * `php-fpm` (ex. 8.1) **et** `php-curl`
  * `curl` (pour tester)
* Un nom de domaine pointant vers votre serveur.

Installation des paquets (ex. Ubuntu) :

```bash
sudo apt update
sudo apt install -y nginx php8.1-fpm php8.1-curl curl
```

> Adaptez `8.1` si vous avez une autre version de PHP.

---

## 2) Arborescence & fichiers

Créez le dossier du site et sa zone de cache :

```bash
sudo mkdir -p /var/www/halving-nito/cache
```

Placez **vos deux fichiers** à la racine :

```
/var/www/halving-nito/index.html
/var/www/halving-nito/nito_summary.php
```

Droits (utilisateur web `www-data`) :

```bash
sudo chown -R www-data:www-data /var/www/halving-nito
sudo find /var/www/halving-nito -type d -exec chmod 775 {} \;
sudo find /var/www/halving-nito -type f -exec chmod 664 {} \;
```

---

## 3) Nginx (vhost)

Créer/éditer `/etc/nginx/sites-available/halving-nito.nitopool.fr` :

```nginx
# HTTP -> HTTPS
server {
    listen 80;
    server_name halving-nito.nitopool.fr;
    return 301 https://$server_name$request_uri;
}

# HTTPS
server {
    listen 443 ssl http2;
    server_name halving-nito.nitopool.fr;

    # Certificats (Let’s Encrypt par ex.)
    ssl_certificate     /etc/letsencrypt/live/halving-nito.nitopool.fr/fullchain.pem;
    ssl_certificate_key /etc/letsencrypt/live/halving-nito.nitopool.fr/privkey.pem;

    root  /var/www/halving-nito;
    index index.html;

    # Static
    location ~* \.(css|js|png|jpg|jpeg|gif|ico|svg|woff2?)$ {
        expires 1y;
        add_header Cache-Control "public, immutable";
    }

    # PHP (⚠️ adaptez la version du socket à VOTRE PHP-FPM)
    location ~ \.php$ {
        include snippets/fastcgi-php.conf;
        fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
        fastcgi_pass unix:/run/php/php8.1-fpm.sock;
    }

    # Endpoint JSON (lu par index.html)
    location = /nito_summary.php {
        include snippets/fastcgi-php.conf;
        fastcgi_param SCRIPT_FILENAME $document_root/nito_summary.php;
        fastcgi_pass unix:/run/php/php8.1-fpm.sock;

        add_header Access-Control-Allow-Origin "*" always;
        add_header Cache-Control "public, max-age=5, stale-while-revalidate=30" always;
    }
}
```

Activer & recharger :

```bash
sudo ln -sf /etc/nginx/sites-available/halving-nito.nitopool.fr /etc/nginx/sites-enabled/
sudo nginx -t && sudo systemctl reload nginx
```

Vérifier PHP-FPM :

```bash
systemctl status php8.1-fpm
```

---

## 4) Vérifications rapides

1. **Endpoint JSON** (doit répondre `200` + JSON) :

```bash
curl -si https://halving-nito.nitopool.fr/nito_summary.php | head
```

2. **Contenu JSON lisible** :

```bash
curl -s https://halving-nito.nitopool.fr/nito_summary.php
```

3. **Page web** :

* Ouvrez `https://halving-nito.nitopool.fr/`.
* Les cartes s’affichent, le compteur descend à la seconde.
* Les données se mettent à jour automatiquement toutes les **10 s** (une requête vers `/nito_summary.php` apparaît dans l’onglet Réseau du navigateur).

---

## 5) Ce que fait chaque fichier

* **`nito_summary.php`**

  * Appelle `https://nito-explorer.nitopool.fr/ext/getsummary` (1 seul appel pour bloc/supply/difficulty).
  * Normalise et arrondit : *difficulty* et *supply* en entiers, *hashrate* en TH/s ou PH/s lisible.
  * Calcule l’ETA **commune** (1 bloc = 60 s) et renvoie `targetHalvingTs`.
  * Écrit un **cache** minimal dans `/var/www/halving-nito/cache/state.json`.

* **`index.html`**

  * Affiche les infos (bloc courant, supply, difficulty, hashrate…).
  * Met à jour le chrono **chaque seconde** à partir de `targetHalvingTs`.
  * Rafraîchit toutes les **10 s** auprès de `/nito_summary.php` (tout le monde voit la même base).

---



