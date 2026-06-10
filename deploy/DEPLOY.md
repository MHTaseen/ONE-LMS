# Deploy BRACU Thesis Prototype (Free Hosting)

Your project is **PHP + MySQL**. It cannot run on **GitHub Pages** (static only).  
Use **InfinityFree** (free PHP + MySQL) — works on phones via a public URL.

**Time needed:** ~30–45 minutes

---

## What you already have in this folder

| File | Purpose |
|------|---------|
| `deploy/bracu_thesis_full.sql` | Full database export (users, courses, grades, etc.) |
| `deploy/thesis-prototype-upload.zip` | Run `prepare_upload.ps1` to create website ZIP |
| `config.production.php.example` | Template for live database credentials |

---

## Step 1 — Create free hosting account

1. Go to **https://infinityfree.com** and click **Sign Up** (free).
2. Verify your email.
3. In the panel, click **Create Account** (hosting account).
4. Choose **Create Website** → subdomain, e.g. `bracu-thesis.infinityfreeapp.com`.
5. Wait until status is **Active** (a few minutes).

---

## Step 2 — Create MySQL database

1. Open your site in the InfinityFree panel → **MySQL Databases**.
2. Click **Create Database**.
3. Save these details (you will need them):
   - **MySQL Hostname** (e.g. `sql301.infinityfree.com`)
   - **Database Name** (e.g. `if0_12345678_bracu`)
   - **Username** (e.g. `if0_12345678`)
   - **Password** (the one you set)

---

## Step 3 — Import your database

1. In the panel, open **phpMyAdmin** for your database.
2. Select your database on the left.
3. Click **Import** tab.
4. Choose file: `deploy/bracu_thesis_full.sql` from your PC.
5. Click **Go** and wait until you see success.
6. Check **users** table — your student/teacher accounts should be there.

---

## Step 4 — Prepare website files on your PC

Open PowerShell in the project folder and run:

```powershell
cd E:\xampp\htdocs\thesis-prototype\deploy
powershell -ExecutionPolicy Bypass -File .\prepare_upload.ps1
```

This creates `deploy/thesis-prototype-upload.zip`.

---

## Step 5 — Upload files via FTP

1. In InfinityFree panel → **FTP Details** (note host, username, password).
2. Download **FileZilla** (free): https://filezilla-project.org
3. Connect to FTP:
   - Host: `ftpupload.net` (or the host shown in panel)
   - Username / Password: from panel
   - Port: `21`
4. On the remote side, open **`htdocs`** folder.
5. Delete default files inside `htdocs` (e.g. `index.html`, `index2.html`).
6. Upload **all contents** from the ZIP (extract locally first, then upload), OR upload the ZIP and extract on server if your host allows.

**Important folders to upload:**
- All `.php` files
- `style.css`, `responsive.css`, `theme.js`
- `includes/` folder
- `uploads/` folder (empty subfolders for file uploads)
- `.htaccess`

**Do NOT upload:** `deploy/` folder, `config.production.php` yet (you create it on server).

---

## Step 6 — Configure live database connection

1. On the server (File Manager or FTP), copy `config.production.php.example` → `config.production.php`.
2. Edit `config.production.php` with your MySQL details from Step 2:

```php
define('DB_HOST', 'sql301.infinityfree.com');
define('DB_PORT', '3306');
define('DB_USER', 'if0_12345678');
define('DB_PASS', 'your_password_here');
define('DB_NAME', 'if0_12345678_bracu');
```

3. Save the file.

---

## Step 7 — Test your live site

Open in browser (phone or PC):

```
https://YOUR-SUBDOMAIN.infinityfreeapp.com/login.php
```

Example: `https://bracu-thesis.infinityfreeapp.com/login.php`

- Log in with your **student email/ID** and password.
- If you forgot password, use **Reset password** on login page.
- Test on phone using the same URL.

---

## Step 8 — Send link to your teacher

Share:

```
https://YOUR-SUBDOMAIN.infinityfreeapp.com/login.php
```

Optional: create a **teacher test account** before sharing, or share existing teacher credentials.

---

## GitHub (optional — code backup only)

GitHub stores code; it does **not** run PHP for free.

```bash
cd E:\xampp\htdocs\thesis-prototype
git init
git add .
git commit -m "Thesis prototype ready for deployment"
```

Create a repo on GitHub and push. Your **live site** still uses InfinityFree (or similar).

---

## Troubleshooting

| Problem | Fix |
|---------|-----|
| Database connection failed | Check `config.production.php` host, user, pass, db name |
| Blank white page | Enable errors temporarily or check InfinityFree error logs |
| 403 Forbidden | Ensure `index.php` and `login.php` are in `htdocs` |
| Uploads fail | `uploads/` subfolders must exist and be writable (chmod 755) |
| Login fails | Re-import SQL or use Reset password |

---

## Re-export database before final demo

If you add more data locally before submitting:

```powershell
E:\xampp\mysql\bin\mysqldump.exe -u root --port=3307 bracu_thesis -r "E:\xampp\htdocs\thesis-prototype\deploy\bracu_thesis_full.sql"
```

Then **Import** again in phpMyAdmin (drop old tables first or use a fresh database).

---

## Security note for thesis demo

This is a prototype. For production you would hide error messages and use HTTPS only. InfinityFree provides free HTTPS on their subdomain.
