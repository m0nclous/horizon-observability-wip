# shellcheck shell=bash
#
# Shared reading of planning/TASKS.md for board.sh and set-status.sh (sourced, not executed).
#
# A task is a heading "### Txx Title — `status`" with an optional tail "(depends on T02, T03–T08)",
# "(depends only on T00; ...)" or "(depends on everything)". Tasks without such a tail inherit the dependencies of
# their phase heading "## Phase B: ... (all depend on T02; ...)". Right under a heading, set-status.sh adds the lines
# "QA: <stamp>: text", "Owner question: <stamp>: text" and "Owner answer: <stamp>: text", newest first.
#
# awk runs with LC_ALL=C (byte semantics) so mawk, gawk and BSD awk read the em and en dashes the same way.

BOARD_STATUSES="todo|in progress|testing|needs owner|done"
BOARD_AREAS="exporter attempts queues http batches discovery horizon-ui grafana live ci docs process"

# Prints one tab-separated line per task: id, title, status, phase, dependencies (space-separated ids, may be empty).
board_parse_tasks() {
    local file="$1"
    [[ -f "$file" ]] || {
        echo "board: task list not found: $file" >&2
        return 1
    }
    LC_ALL=C awk -v statuses="$BOARD_STATUSES" '
        # Every id of a list such as "T03–T08, T10 and T12" (en dash or hyphen ranges), space-separated.
        function expand(clause,    out, id, range, lo, hi, width, k) {
            out = ""
            while (match(clause, /T[0-9]+/)) {
                id = substr(clause, RSTART, RLENGTH)
                clause = substr(clause, RSTART + RLENGTH)
                if (match(clause, /^(-|–)T[0-9]+/)) {
                    range = substr(clause, RSTART, RLENGTH)
                    clause = substr(clause, RSTART + RLENGTH)
                    match(range, /T[0-9]+$/)
                    hi = substr(range, RSTART + 1, RLENGTH - 1) + 0
                    lo = substr(id, 2) + 0
                    width = length(id) - 1
                    for (k = lo; k <= hi; k++) {
                        out = out " " sprintf("T%0" width "d", k)
                    }
                } else {
                    out = out " " id
                }
            }
            return out
        }
        /^## Phase / {
            phase = $3
            sub(/:$/, "", phase)
            phase_deps = ""
            if (match($0, /all depend on [^;)]*/)) {
                phase_deps = expand(substr($0, RSTART, RLENGTH))
            }
            next
        }
        /^### T[0-9]+ / {
            if (match($0, " — `(" statuses ")`") == 0) {
                next
            }
            status = substr($0, RSTART, RLENGTH)
            tail = substr($0, RSTART + RLENGTH)
            sub(/^[^`]*`/, "", status)
            sub(/`$/, "", status)
            id = $2
            title = substr($0, 5 + length(id) + 1)
            sub(/ — .*/, "", title)
            deps = phase_deps
            if (match(tail, /depends (only )?on [^;)]*/)) {
                clause = substr(tail, RSTART, RLENGTH)
                deps = (clause ~ /everything/) ? "*" : expand(clause)
            }
            n++
            ids[n] = id
            titles[n] = title
            states[n] = status
            phases[n] = phase
            depends[n] = deps
        }
        END {
            for (i = 1; i <= n; i++) {
                out = ""
                split("", seen)
                if (depends[i] == "*") {
                    for (j = 1; j <= n; j++) {
                        if (j != i) {
                            out = out " " ids[j]
                        }
                    }
                } else {
                    count = split(depends[i], list, " ")
                    for (j = 1; j <= count; j++) {
                        if (list[j] != ids[i] && !(list[j] in seen)) {
                            seen[list[j]] = 1
                            out = out " " list[j]
                        }
                    }
                }
                sub(/^ /, "", out)
                printf "%s\t%s\t%s\t%s\t%s\n", ids[i], titles[i], states[i], phases[i], out
            }
        }
    ' "$file"
}

# Prints the notes of every task, oldest first per task: id, kind (qa, question, answer), text (without the prefix).
board_parse_notes() {
    local file="$1"
    LC_ALL=C awk '
        /^### T[0-9]+ / {
            current = $2
            next
        }
        /^##? / {
            current = ""
            next
        }
        current != "" {
            kind = ""
            if (/^QA: /) {
                kind = "qa"
                text = substr($0, 5)
            } else if (/^Owner question: /) {
                kind = "question"
                text = substr($0, 17)
            } else if (/^Owner answer: /) {
                kind = "answer"
                text = substr($0, 15)
            }
            if (kind != "") {
                if (!(current in count)) {
                    order[++tasks] = current
                }
                count[current]++
                kinds[current, count[current]] = kind
                texts[current, count[current]] = text
            }
        }
        END {
            for (t = 1; t <= tasks; t++) {
                id = order[t]
                for (k = count[id]; k >= 1; k--) {
                    printf "%s\t%s\t%s\n", id, kinds[id, k], texts[id, k]
                }
            }
        }
    ' "$file"
}

# Loads the task list into TASK_IDS (file order) and the maps TASK_TITLE, TASK_STATUS, TASK_PHASE, TASK_DEPS and
# TASK_NOTES (newline-separated "kind<TAB>text", oldest first).
board_load_tasks() {
    local file="$1" parsed notes id title status phase deps kind text
    parsed="$(board_parse_tasks "$file")" || return 1
    notes="$(board_parse_notes "$file")"
    TASK_IDS=()
    # shellcheck disable=SC2034 # read by board.sh and set-status.sh
    declare -gA TASK_TITLE=() TASK_STATUS=() TASK_PHASE=() TASK_DEPS=() TASK_NOTES=()
    while IFS=$'\t' read -r id title status phase deps; do
        [[ -n "$id" ]] || continue
        if [[ -n "${TASK_STATUS[$id]:-}" ]]; then
            echo "board: warning: $id appears twice in $file; the first heading wins" >&2
            continue
        fi
        TASK_IDS+=("$id")
        # shellcheck disable=SC2034
        TASK_TITLE[$id]="$title"
        TASK_STATUS[$id]="$status"
        # shellcheck disable=SC2034
        TASK_PHASE[$id]="$phase"
        TASK_DEPS[$id]="$deps"
    done <<<"$parsed"
    while IFS=$'\t' read -r id kind text; do
        [[ -n "$id" && -n "${TASK_STATUS[$id]:-}" ]] || continue
        TASK_NOTES[$id]+="$kind"$'\t'"$text"$'\n'
    done <<<"$notes"
}

# Prints the dependencies of a task that are not done yet (space-separated; unknown ids count as not done).
board_missing_deps() {
    local id="$1" dep pending=()
    for dep in ${TASK_DEPS[$id]:-}; do
        [[ "${TASK_STATUS[$dep]:-}" == "done" ]] || pending+=("$dep")
    done
    echo "${pending[*]}"
}

# Prints "T12 (todo), T13 (unknown)" for a list of ids.
board_describe_ids() {
    local dep out=""
    for dep in $1; do
        out+="${out:+, }$dep (${TASK_STATUS[$dep]:-unknown})"
    done
    echo "$out"
}

# Prints the ids of the tasks with a status, space-separated, optionally without one task.
board_ids_with_status() {
    local status="$1" except="${2:-}" id out=()
    for id in "${TASK_IDS[@]}"; do
        [[ "${TASK_STATUS[$id]}" == "$status" && "$id" != "$except" ]] && out+=("$id")
    done
    echo "${out[*]}"
}

# Loads tools/board/areas.tsv ("Txx<TAB>area" lines, # comments) into TASK_AREA.
board_load_areas() {
    local file="$1" id area rest
    declare -gA TASK_AREA=()
    [[ -f "$file" ]] || {
        echo "board: warning: no area map at $file; the Area field stays empty" >&2
        return 0
    }
    while IFS=$'\t' read -r id area rest; do
        [[ -z "$id" || "$id" == \#* ]] && continue
        [[ "$id" =~ ^T[0-9]+$ && -n "$area" ]] || {
            echo "board: invalid line in $file: $id $area" >&2
            return 1
        }
        [[ " $BOARD_AREAS " == *" $area "* ]] || {
            echo "board: unknown area \"$area\" for $id in $file (known: $BOARD_AREAS)" >&2
            return 1
        }
        # shellcheck disable=SC2034 # read by board.sh
        TASK_AREA[$id]="$area"
    done <"$file"
}
