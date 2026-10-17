# Summarises the stub's GitHub state: labels, issues with their comments, board fields and cards.
def flat: gsub("\n"; "\\n");
.project as $p
| .user as $user
| (.labels | sort_by(.name)[] | "label \(.name) #\(.color) \(.description)"),
  (.issues | sort_by(.number)[]
    | "issue #\(.number) \(.state) \(.title) [\(.labels | join(", "))]\(if .pinned then " pinned" else "" end)",
      (.comments[] | "  comment\(if .author != $user then " by \(.author)" else "" end): \(.body | flat)")),
  ($p.fields[] | select(.type == "ProjectV2SingleSelectField" or ((.dataType // "") | IN("NUMBER", "DATE")))
    | "field \(.name): \(if .options then [.options[].name] | join(", ") else .dataType end)"),
  ($p.items[] | . as $item
    | "card \($item.url | sub(".*/issues/"; "#")) "
      + ([$p.fields[] | . as $field | select($item.values[$field.id] != null)
          | "\($field.name)=\(if $field.options then ($field.options[] | select(.id == $item.values[$field.id]) | .name)
              else $item.values[$field.id] | tostring end)"] | join("; ")))
