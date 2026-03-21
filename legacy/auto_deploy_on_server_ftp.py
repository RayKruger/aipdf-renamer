import os
import ftplib
import hashlib
import json
from fnmatch import fnmatch
import time

# --------------------------------------------------
# Setup
# --------------------------------------------------
#grant
#HOST = "ftpupload.net"
#USER = "thsi_39822781"
#PASS = "AX0t1JZ3"

#LOCAL_DIR = "."
#REMOTE_DIR = "/public_html"
#GITIGNORE = ".gitignore"
#port 21

# Remote folder
#REMOTE_DIR = "/nelsonkrugerproject.is-great.net/htdocs"

HOST = "ftpupload.net"
USER = "thsi_39822703"
PASS = "H?sfuPFe"

LOCAL_DIR = "."
REMOTE_DIR = "/public_html"
GITIGNORE = ".gitignore"

# Remote folder
REMOTE_DIR = "/aipdfrenamerandsearch.totalh.net/htdocs"


# Path where deploy.py is located
LOCAL_DIR = os.path.dirname(os.path.abspath(__file__))

# Path to .gitignore (one level above)
GITIGNORE = os.path.abspath(os.path.join(LOCAL_DIR, "..", ".gitignore"))




CHANGE_ONLY = True   # upload only changed files
# CHANGE_ONLY = False  # mirror mode - upload everything

HASH_FILE = os.path.join(LOCAL_DIR, ".deploy_hashes.json")
WATCH_MODE = True  #ontinuously monitor folder -Auto-deploy File is changed or created
poll_time=1 #seconds
# --------------------------------------------------
# HASH CALCULATION
# --------------------------------------------------
def hash_file(path):
    h = hashlib.md5()
    with open(path, "rb") as f:
        h.update(f.read())
    return h.hexdigest()


def load_hashes():
    if os.path.exists(HASH_FILE):
        with open(HASH_FILE, "r") as f:
            return json.load(f)
    return {}


def save_hashes(hashes):
    with open(HASH_FILE, "w") as f:
        json.dump(hashes, f, indent=2)


# --------------------------------------------------
# Load .gitignore patterns
# --------------------------------------------------
def load_gitignore():
    patterns = []
    if os.path.exists(GITIGNORE):
        with open(GITIGNORE, "r") as f:
            for line in f:
                line = line.strip()
                if line and not line.startswith("#"):
                    patterns.append(line)
    return patterns


def matches_gitignore(path, pattern):
    if pattern.endswith("/"):
        if path.startswith(pattern[:-1]):
            return True
    if "*" in pattern:
        if fnmatch(path, pattern):
            return True
    if path == pattern or path.endswith(pattern):
        return True
    return False


def should_ignore(path, patterns):
    for p in patterns:
        if matches_gitignore(path, p):
            return True
    # ignore dot folders & files
    if any(part.startswith(".") for part in path.split("/")):
        return True
    return False


# --------------------------------------------------
# FTP handling
# --------------------------------------------------
def ftp_connect():
    ftp = ftplib.FTP(HOST)
    ftp.login(USER, PASS)
    return ftp


def make_dirs(ftp, path):
    parts = path.strip("/").split("/")
    for part in parts:
        try:
            ftp.cwd(part)
        except:
            try:
                ftp.mkd(part)
            except:
                pass
            ftp.cwd(part)
    ftp.cwd("/")


def upload_file(ftp, local_path, remote_path):
    remote_dir = os.path.dirname(remote_path)
    try:
        ftp.cwd(remote_dir)
    except:
        make_dirs(ftp, remote_dir)
        ftp.cwd(remote_dir)

    print("⬆ Uploading:", local_path)
    with open(local_path, "rb") as f:
        ftp.storbinary("STOR " + os.path.basename(remote_path), f)


# --------------------------------------------------
# MAIN DEPLOY (single run)
# --------------------------------------------------
SCRIPT_NAME = os.path.basename(__file__)

def deploy_once():
    patterns = load_gitignore()
    hashes = load_hashes()
    updated_hashes = {}
    ftp = ftp_connect()
    uploaded_files = 0

    for root, dirs, files in os.walk(LOCAL_DIR):
        for filename in files:
            local_path = os.path.join(root, filename).replace("\\", "/")
            relative = os.path.relpath(local_path, LOCAL_DIR).replace("\\", "/")

            if relative == SCRIPT_NAME:
                continue

            if should_ignore(relative, patterns):
                continue

            file_hash = hash_file(local_path)
            updated_hashes[relative] = file_hash

            if CHANGE_ONLY and hashes.get(relative) == file_hash:
                continue  # unchanged

            remote_path = f"{REMOTE_DIR}/{relative}"
            upload_file(ftp, local_path, remote_path)
            uploaded_files += 1

    ftp.quit()
    save_hashes(updated_hashes)

    if uploaded_files == 0:
        print("✓ No changes to upload")
    else:
        print(f"✓ Uploaded {uploaded_files} changed files")

    print("Mode:", "Changed-only" if CHANGE_ONLY else "Mirror")
    return uploaded_files


# --------------------------------------------------
# WATCH MODE
# --------------------------------------------------
def watch_and_deploy():
    print("👀 Watching for real changes... (auto-deploy ON)")
    last_snapshot = {}

    while True:
        current_snapshot = {}

        # build snapshot of mod-times
        for root, dirs, files in os.walk(LOCAL_DIR):
            for filename in files:
                relative = os.path.relpath(os.path.join(root, filename), LOCAL_DIR).replace("\\", "/")

                # ignore script + hash file + dot-files
                if relative == SCRIPT_NAME:
                    continue
                if relative == ".deploy_hashes.json":
                    continue
                if relative.startswith(".") or "/." in relative:
                    continue

                full = os.path.join(root, filename)
                current_snapshot[relative] = os.path.getmtime(full)

        # detect actual changes
        if current_snapshot != last_snapshot:
            print("\n🔄 Change detected — Checking server upload needs...")

            uploaded = deploy_once()

            if uploaded > 0:
                print(f"🚀 Uploaded {uploaded} changed file(s)")
            else:
                # Only print once, not nonstop
                print("✓ No real changes to upload")

        last_snapshot = current_snapshot
        time.sleep(poll_time)


# --------------------------------------------------
# BOOTSTRAP
# --------------------------------------------------
if __name__ == "__main__":
    if WATCH_MODE:
        watch_and_deploy()
    else:
        deploy_once()