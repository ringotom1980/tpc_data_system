# TPC Auto Uploader MVP

這是本機工具雛形，需在使用者 Windows 電腦執行。網站會呼叫 `http://127.0.0.1:17888`，本機工具會用該電腦的 IP 登入另一系統並下載 Excel，再把檔案回傳給瀏覽器走原本匯入流程。

## 執行

從網站下載時，請下載：

- `TPCAutoUploaderSetup.exe`

目前網站會下載 `TPCAutoUploaderSetup.zip`。請先解壓縮，再雙擊裡面的 `TPCAutoUploaderSetup.exe`。安裝精靈會把本機工具安裝到使用者電腦並啟動。這個安裝檔已包含 Python 執行環境，使用者不需要另外安裝 Python。

從專案資料夾直接執行時：

```powershell
python tools\auto_uploader\tpc_auto_uploader.py
```

第一次啟動會開啟設定視窗，請填：

- 登入網址
- Excel 下載網址
- 帳號
- 密碼
- 帳號欄位名稱，預設 `username`
- 密碼欄位名稱，預設 `password`

設定視窗會顯示「本機配對碼」。第一次在網站使用自動上傳時，需要貼上這組配對碼。配對碼不是另一系統密碼，只是避免其他網站任意呼叫本機工具。

下載網址可使用：

```text
{withdraw_date}
{contractor_code}
```

例如：

```text
https://example.local/export.xlsx?date={withdraw_date}&contractor={contractor_code}
```

## 安全限制

- 只綁定 `127.0.0.1`
- CORS 只允許 `https://tpc.jinghong.pw` 和本機測試網址
- `/sync` 必須帶本機配對碼
- 密碼用 Windows DPAPI 以目前 Windows 使用者身分加密後存放在 `%APPDATA%\TPCDataSystem\auto_uploader.json`

## 後續包裝

正式使用時建議把這支 Python 程式包成 Windows `.exe` 或 `.msi`，並設定開機自動啟動。
