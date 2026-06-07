"""
TPC Auto Uploader local helper MVP.

Security model:
- Binds to 127.0.0.1 only.
- Only allows browser calls from known TPC origins.
- /sync requires a local pairing token.
- Password is protected with Windows DPAPI for the current Windows user.
"""

from __future__ import annotations

import base64
import ctypes
import json
import mimetypes
import os
import secrets
import threading
import urllib.parse
import urllib.request
from ctypes import wintypes
from dataclasses import asdict, dataclass
from http.cookiejar import CookieJar
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer
from html.parser import HTMLParser
from pathlib import Path
from typing import Dict, Tuple

import tkinter as tk
from tkinter import messagebox


HOST = "127.0.0.1"
PORT = 17888
ALLOWED_ORIGINS = {
    "https://tpc.jinghong.pw",
    "http://127.0.0.1:8088",
    "http://localhost:8088",
}
APP_DIR = Path(os.environ.get("APPDATA", str(Path.home()))) / "TPCDataSystem"
CONFIG_PATH = APP_DIR / "auto_uploader.json"


class DATA_BLOB(ctypes.Structure):
    _fields_ = [("cbData", wintypes.DWORD), ("pbData", ctypes.POINTER(ctypes.c_char))]


crypt32 = ctypes.windll.crypt32
kernel32 = ctypes.windll.kernel32


def _blob(data: bytes) -> DATA_BLOB:
    buf = ctypes.create_string_buffer(data)
    return DATA_BLOB(len(data), ctypes.cast(buf, ctypes.POINTER(ctypes.c_char)))


def protect_secret(value: str) -> str:
    data_in = _blob(value.encode("utf-8"))
    data_out = DATA_BLOB()
    if not crypt32.CryptProtectData(ctypes.byref(data_in), None, None, None, None, 0, ctypes.byref(data_out)):
        raise RuntimeError("無法加密密碼")
    try:
        raw = ctypes.string_at(data_out.pbData, data_out.cbData)
        return base64.b64encode(raw).decode("ascii")
    finally:
        kernel32.LocalFree(data_out.pbData)


def unprotect_secret(value: str) -> str:
    if not value:
        return ""
    raw = base64.b64decode(value.encode("ascii"))
    data_in = _blob(raw)
    data_out = DATA_BLOB()
    if not crypt32.CryptUnprotectData(ctypes.byref(data_in), None, None, None, None, 0, ctypes.byref(data_out)):
        raise RuntimeError("無法解密密碼")
    try:
        return ctypes.string_at(data_out.pbData, data_out.cbData).decode("utf-8")
    finally:
        kernel32.LocalFree(data_out.pbData)


@dataclass
class Config:
    login_url: str = ""
    download_url: str = ""
    username: str = ""
    password_protected: str = ""
    username_field: str = "username"
    password_field: str = "password"
    local_token: str = ""


class LoginFormParser(HTMLParser):
    def __init__(self) -> None:
        super().__init__()
        self.in_form = False
        self.form_action = ""
        self.inputs: Dict[str, str] = {}

    def handle_starttag(self, tag: str, attrs: list[Tuple[str, str | None]]) -> None:
        attrs_dict = {k.lower(): (v or "") for k, v in attrs}
        if tag.lower() == "form" and not self.in_form:
            self.in_form = True
            self.form_action = attrs_dict.get("action", "")
        if tag.lower() == "input" and self.in_form:
            name = attrs_dict.get("name", "")
            if name:
                self.inputs[name] = attrs_dict.get("value", "")

    def handle_endtag(self, tag: str) -> None:
        if tag.lower() == "form" and self.in_form:
            self.in_form = False


def load_config() -> Config:
    if not CONFIG_PATH.is_file():
        return Config(local_token=secrets.token_urlsafe(24))
    try:
        data = json.loads(CONFIG_PATH.read_text(encoding="utf-8"))
        cfg = Config(**{k: data.get(k, getattr(Config(), k)) for k in asdict(Config()).keys()})
        if not cfg.local_token:
            cfg.local_token = secrets.token_urlsafe(24)
            save_config(cfg)
        return cfg
    except Exception:
        return Config(local_token=secrets.token_urlsafe(24))


def save_config(cfg: Config) -> None:
    APP_DIR.mkdir(parents=True, exist_ok=True)
    CONFIG_PATH.write_text(json.dumps(asdict(cfg), ensure_ascii=False, indent=2), encoding="utf-8")


def is_configured(cfg: Config) -> bool:
    return bool(cfg.download_url and cfg.username and cfg.password_protected)


def allowed_origin(handler: BaseHTTPRequestHandler) -> str:
    origin = handler.headers.get("Origin", "")
    return origin if origin in ALLOWED_ORIGINS else ""


def cors_headers(handler: BaseHTTPRequestHandler) -> None:
    origin = allowed_origin(handler)
    if origin:
        handler.send_header("Access-Control-Allow-Origin", origin)
        handler.send_header("Vary", "Origin")
    handler.send_header("Access-Control-Allow-Methods", "GET, POST, OPTIONS")
    handler.send_header("Access-Control-Allow-Headers", "Content-Type, X-TPC-Local-Token")
    handler.send_header("Access-Control-Expose-Headers", "X-TPC-Filename")


def json_bytes(payload: dict) -> bytes:
    return json.dumps(payload, ensure_ascii=False).encode("utf-8")


def show_settings_window() -> None:
    cfg = load_config()
    root = tk.Tk()
    root.title("TPC Auto Uploader 設定")
    root.geometry("700x410")
    root.resizable(False, False)

    tk.Label(root, text="另一系統下載設定", font=("Microsoft JhengHei", 13, "bold")).grid(
        row=0, column=0, columnspan=2, sticky="w", padx=16, pady=(14, 8)
    )

    password = ""
    try:
        password = unprotect_secret(cfg.password_protected)
    except Exception:
        password = ""

    values = {
        "login_url": cfg.login_url,
        "download_url": cfg.download_url,
        "username": cfg.username,
        "password": password,
        "username_field": cfg.username_field,
        "password_field": cfg.password_field,
    }
    labels = [
        ("登入網址", "login_url"),
        ("Excel 下載網址", "download_url"),
        ("帳號", "username"),
        ("密碼", "password"),
        ("帳號欄位名稱", "username_field"),
        ("密碼欄位名稱", "password_field"),
    ]
    entries: Dict[str, tk.Entry] = {}

    for i, (label, key) in enumerate(labels, start=1):
        tk.Label(root, text=label).grid(row=i, column=0, sticky="e", padx=(16, 8), pady=6)
        entry = tk.Entry(root, width=72, show="*" if key == "password" else "")
        entry.insert(0, values[key])
        entry.grid(row=i, column=1, sticky="w", padx=(0, 16), pady=6)
        entries[key] = entry

    tk.Label(root, text="本機配對碼").grid(row=7, column=0, sticky="e", padx=(16, 8), pady=6)
    token_entry = tk.Entry(root, width=72)
    token_entry.insert(0, cfg.local_token)
    token_entry.configure(state="readonly")
    token_entry.grid(row=7, column=1, sticky="w", padx=(0, 16), pady=6)

    hint = "下載網址可使用 {withdraw_date}、{contractor_code} 作為參數替換。"
    tk.Label(root, text=hint, fg="#666").grid(row=8, column=1, sticky="w", padx=(0, 16), pady=(4, 8))

    def on_save() -> None:
        cfg.login_url = entries["login_url"].get().strip()
        cfg.download_url = entries["download_url"].get().strip()
        cfg.username = entries["username"].get().strip()
        cfg.password_protected = protect_secret(entries["password"].get())
        cfg.username_field = entries["username_field"].get().strip() or "username"
        cfg.password_field = entries["password_field"].get().strip() or "password"
        save_config(cfg)
        messagebox.showinfo("TPC Auto Uploader", "設定已儲存")
        root.destroy()

    btn_frame = tk.Frame(root)
    btn_frame.grid(row=9, column=1, sticky="e", padx=16, pady=12)
    tk.Button(btn_frame, text="取消", command=root.destroy, width=10).pack(side="right", padx=4)
    tk.Button(btn_frame, text="儲存", command=on_save, width=10).pack(side="right", padx=4)

    root.mainloop()


def open_settings_async() -> None:
    threading.Thread(target=show_settings_window, daemon=True).start()


def build_download_url(url: str, withdraw_date: str, contractor_code: str) -> str:
    return url.replace("{withdraw_date}", urllib.parse.quote(withdraw_date)).replace(
        "{contractor_code}", urllib.parse.quote(contractor_code)
    )


def download_excel(cfg: Config, withdraw_date: str, contractor_code: str) -> Tuple[bytes, str, str]:
    if not is_configured(cfg):
        raise RuntimeError("本機工具尚未完成設定")

    password = unprotect_secret(cfg.password_protected)
    cookie_jar = CookieJar()
    opener = urllib.request.build_opener(urllib.request.HTTPCookieProcessor(cookie_jar))
    opener.addheaders = [("User-Agent", "TPC-Auto-Uploader/1.0")]

    if cfg.login_url:
        login_page = opener.open(cfg.login_url, timeout=30).read().decode("utf-8", errors="ignore")
        parser = LoginFormParser()
        parser.feed(login_page)
        payload = dict(parser.inputs)
        payload[cfg.username_field] = cfg.username
        payload[cfg.password_field] = password
        login_target = urllib.parse.urljoin(cfg.login_url, parser.form_action or cfg.login_url)
        opener.open(login_target, data=urllib.parse.urlencode(payload).encode("utf-8"), timeout=30).read()

    response = opener.open(build_download_url(cfg.download_url, withdraw_date, contractor_code), timeout=60)
    content = response.read()
    content_type = response.headers.get("Content-Type", "application/octet-stream")
    disposition = response.headers.get("Content-Disposition", "")

    if b"<html" in content[:500].lower():
        raise RuntimeError("下載結果像是 HTML 頁面，可能登入失敗或下載網址不正確")

    filename = f"auto_{contractor_code}_{withdraw_date}.xlsx"
    if "filename=" in disposition:
        filename = disposition.split("filename=", 1)[1].strip().strip('"')
    elif "excel" not in content_type.lower() and "spreadsheet" not in content_type.lower():
        guessed = mimetypes.guess_extension(content_type.split(";")[0].strip())
        if guessed:
            filename = f"auto_{contractor_code}_{withdraw_date}{guessed}"

    return content, filename, content_type


class Handler(BaseHTTPRequestHandler):
    server_version = "TPCAutoUploader/1.0"

    def do_OPTIONS(self) -> None:
        self.send_response(204)
        cors_headers(self)
        self.end_headers()

    def do_GET(self) -> None:
        if self.path.startswith("/status"):
            cfg = load_config()
            self.send_response(200)
            cors_headers(self)
            self.send_header("Content-Type", "application/json; charset=utf-8")
            self.end_headers()
            self.wfile.write(json_bytes({"success": True, "configured": is_configured(cfg)}))
            return
        self.send_error(404)

    def do_POST(self) -> None:
        if self.path.startswith("/open-settings"):
            open_settings_async()
            self.send_response(200)
            cors_headers(self)
            self.send_header("Content-Type", "application/json; charset=utf-8")
            self.end_headers()
            self.wfile.write(json_bytes({"success": True}))
            return

        if self.path.startswith("/sync"):
            cfg = load_config()
            if self.headers.get("X-TPC-Local-Token", "") != cfg.local_token:
                self.send_response(401)
                cors_headers(self)
                self.send_header("Content-Type", "application/json; charset=utf-8")
                self.end_headers()
                self.wfile.write(json_bytes({"success": False, "message": "本機配對碼不正確"}))
                return
            try:
                length = int(self.headers.get("Content-Length", "0"))
                body = json.loads(self.rfile.read(length).decode("utf-8") or "{}")
                content, filename, content_type = download_excel(
                    cfg,
                    str(body.get("withdraw_date", "")),
                    str(body.get("contractor_code", "")),
                )
                self.send_response(200)
                cors_headers(self)
                self.send_header("Content-Type", content_type)
                self.send_header("X-TPC-Filename", filename)
                self.send_header("Content-Length", str(len(content)))
                self.end_headers()
                self.wfile.write(content)
            except Exception as exc:
                self.send_response(400)
                cors_headers(self)
                self.send_header("Content-Type", "application/json; charset=utf-8")
                self.end_headers()
                self.wfile.write(json_bytes({"success": False, "message": str(exc)}))
            return

        self.send_error(404)

    def log_message(self, fmt: str, *args) -> None:
        print("%s - %s" % (self.address_string(), fmt % args))


def main() -> None:
    APP_DIR.mkdir(parents=True, exist_ok=True)
    if not CONFIG_PATH.exists():
        save_config(load_config())
        open_settings_async()
    server = ThreadingHTTPServer((HOST, PORT), Handler)
    print(f"TPC Auto Uploader running at http://{HOST}:{PORT}")
    server.serve_forever()


if __name__ == "__main__":
    main()
