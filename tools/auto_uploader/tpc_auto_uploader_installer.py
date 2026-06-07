from __future__ import annotations

import os
import shutil
import subprocess
import sys
from pathlib import Path

import tkinter as tk
from tkinter import messagebox


APP_NAME = "TPC Auto Uploader"
EXE_NAME = "TPCAutoUploader.exe"


def resource_path(name: str) -> Path:
    base = Path(getattr(sys, "_MEIPASS", Path(__file__).resolve().parent))
    return base / name


def install_dir() -> Path:
    return Path(os.environ.get("LOCALAPPDATA", str(Path.home()))) / "TPCDataSystem" / "AutoUploader"


def startup_dir() -> Path:
    return Path(os.environ["APPDATA"]) / "Microsoft" / "Windows" / "Start Menu" / "Programs" / "Startup"


def install() -> Path:
    src = resource_path(EXE_NAME)
    if not src.is_file():
        raise RuntimeError(f"找不到安裝資源：{EXE_NAME}")

    target_dir = install_dir()
    target_dir.mkdir(parents=True, exist_ok=True)
    target = target_dir / EXE_NAME
    shutil.copy2(src, target)

    launcher = target_dir / "Start TPC Auto Uploader.cmd"
    launcher.write_text(f'@echo off\r\nstart "" "{target}"\r\n', encoding="utf-8")

    startup = startup_dir() / "TPC Auto Uploader.cmd"
    startup.write_text(f'@echo off\r\nstart "" "{target}"\r\n', encoding="utf-8")

    subprocess.Popen([str(target)], cwd=str(target_dir), close_fds=True)
    return target


def main() -> None:
    root = tk.Tk()
    root.title("TPC Auto Uploader 安裝精靈")
    root.geometry("520x280")
    root.resizable(False, False)

    frame = tk.Frame(root, padx=24, pady=20)
    frame.pack(fill="both", expand=True)

    tk.Label(frame, text="TPC Auto Uploader", font=("Microsoft JhengHei", 18, "bold")).pack(anchor="w")
    tk.Label(
        frame,
        text=(
            "此精靈會將本機工具安裝到目前 Windows 使用者帳戶，"
            "並設定開機自動啟動。安裝完成後會開啟設定視窗，"
            "請輸入另一系統下載網址、帳號與密碼。"
        ),
        wraplength=460,
        justify="left",
        pady=16,
    ).pack(anchor="w")

    path_label = tk.Label(frame, text=f"安裝位置：{install_dir()}", fg="#555", wraplength=460, justify="left")
    path_label.pack(anchor="w", pady=(0, 16))

    buttons = tk.Frame(frame)
    buttons.pack(side="bottom", anchor="e")

    def on_install() -> None:
        try:
            target = install()
            messagebox.showinfo(APP_NAME, f"安裝完成。\n\n{target}")
            root.destroy()
        except Exception as exc:
            messagebox.showerror(APP_NAME, str(exc))

    tk.Button(buttons, text="取消", width=12, command=root.destroy).pack(side="right", padx=6)
    tk.Button(buttons, text="安裝", width=12, command=on_install).pack(side="right", padx=6)

    root.mainloop()


if __name__ == "__main__":
    main()
