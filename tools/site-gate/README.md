# Site gate

Locks a whole Ekklesia deployment behind one shared password, asked once per
browser session. Used for the demo, which runs on real member data.

Why not HTTP Basic authentication: the web server labels every 401 as a password
challenge, including Ekklesia's own "not signed in" API answers, so the browser
asked for the site password again on almost every page.

## Install on the server

1. Copy `site-gate.php` to the site root.
2. Create `~/.ekklesia-site-gate.php` (the account's home folder, outside every
   web folder, mode 600):

   ```php
   <?php
   return [
       'token' => '<64 random hex characters>',
       'password_hash' => '<password_hash("the site password", PASSWORD_DEFAULT)>',
       'name' => 'Ekklesia demo',
   ];
   ```
3. Create `.htaccess-site-lock` in the site root with the same token:

   ```apache
   # ---- Site lock (server-only; tools/deploy.sh keeps it on top of .htaccess) ----
   <IfModule mod_rewrite.c>
       RewriteEngine On
       RewriteCond %{REQUEST_URI} !^/site-gate\.php$
       RewriteCond %{HTTP_COOKIE} !(^|;\s*)ekklesia_site=<token>(;|$)
       RewriteRule ^ /site-gate.php [L]
   </IfModule>
   <IfModule mod_headers.c>
       Header always set X-Robots-Tag "noindex, nofollow"
       Header always set Cache-Control "private, no-store"
       Header always unset Expires
   </IfModule>
   # ---- end site lock ----
   ```

   `Cache-Control: private, no-store` matters on hosting with a CDN in front:
   without it the CDN stores static files and hands them to visitors who never
   passed the gate.
4. Deploy (or put the lock on top of `.htaccess` by hand); `tools/deploy.sh`
   keeps it there.

To change the password, replace `password_hash` in the settings file. To sign
everyone out, change the token in both files.
