' Menyalakan worker antrean kirim TANPA jendela (berjalan di latar).
' Untuk menghentikan: jalankan scripts\worker-stop.bat (berhenti dengan rapi).
Option Explicit
Dim fso, sh, root, logDir, cmd
Set fso = CreateObject("Scripting.FileSystemObject")
Set sh  = CreateObject("WScript.Shell")

' Root proyek = folder induk dari folder script ini.
root = fso.GetParentFolderName(fso.GetParentFolderName(WScript.ScriptFullName))
sh.CurrentDirectory = root

logDir = root & "\writable\logs"
If Not fso.FolderExists(logDir) Then fso.CreateFolder(logDir)

' Jalankan daemon tersembunyi (0) dan jangan tunggu (False).
cmd = "cmd /c php spark tukangkirim:work >> """ & logDir & "\worker.log"" 2>&1"
sh.Run cmd, 0, False
