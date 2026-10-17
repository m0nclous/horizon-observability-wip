# Renders one logged gh call (a JSON array of its arguments) as one shell-like line: plain words stay as they are,
# anything else is single-quoted with newlines shown as \n. The generated issue bodies appear by name; the tests
# compare them on their own.
def quote:
  if test("^[A-Za-z0-9_@%+=:,./-]+$") then .
  else "'" + (gsub("'"; "'\\''") | gsub("\n"; "\\n")) + "'"
  end;
def name:
  if startswith("Mirrors task **") then "<task body>"
  elif startswith("<!-- board:report -->") then "<report body>"
  elif startswith("<!-- board:stop-the-line -->") then "<ci body>"
  else . end;
"gh " + (map(name | quote) | join(" "))
