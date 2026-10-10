nginx vhost snippet for /church_portal

Place the following vhost snippet on the host that serves christlikeness.local.
It mounts the repository `public/` directory at `http://christlikeness.local/church_portal/`.

Save as /etc/nginx/sites-available/christlikeness (requires sudo), then
ln -s /etc/nginx/sites-available/christlikeness /etc/nginx/sites-enabled/ and reload nginx.

nginx snippet:

    location /church_portal/ {
        alias /path/to/ChurchPortal/public/;
        try_files $uri $uri/ @church_portal_front;
    }

    location = /church_portal/index.php {
        internal;
        include snippets/fastcgi-php.conf;
        fastcgi_param SCRIPT_FILENAME /path/to/ChurchPortal/public/index.php;
        fastcgi_param SCRIPT_NAME /church_portal/index.php;
        fastcgi_param PORTAL_BASE_PATH /church_portal;
        fastcgi_pass unix:/run/php/php8.4-fpm.sock;
    }

    location @church_portal_front {
        rewrite ^ /church_portal/index.php last;
    }

Hosts file entry (on your local machine):

    127.0.0.1   christlikeness.local

Commands to enable and reload (run as root or with sudo):

    # copy the snippet to nginx (edit with sudo)
    sudo tee /etc/nginx/sites-available/christlikeness > /dev/null <<'EOF'
    (paste the snippet from above)
    EOF
    sudo ln -sf /etc/nginx/sites-available/christlikeness /etc/nginx/sites-enabled/christlikeness
    sudo nginx -t && sudo systemctl reload nginx

If you cannot modify the host's nginx, use the PHP built-in server for quick local testing:

    cd /mnt/ai/workspaces/christlikeness/church_portal
    php -S 127.0.0.1:8765 -t public public/index.php

Then open http://127.0.0.1:8765/ (the portal will detect an empty PORTAL_BASE_PATH).

Notes:
- The nginx config uses alias and therefore needs the named location and SCRIPT_NAME pinning as shown to ensure correct dispatch.
- The exact-match `location = /church_portal/index.php` is marked `internal` so nginx can route rewritten requests into PHP without exposing arbitrary `.php` execution under `/church_portal/`.
- fastcgi_pass socket path may differ by distro (php8.4-fpm.sock vs php-fpm.sock). Adjust accordingly.
- The PORTAL_BASE_PATH env var is set via fastcgi_param above; if you prefer, you can export it in your FPM pool config.
