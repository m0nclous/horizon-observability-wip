# shellcheck shell=bash
#
# Shared reading of planning/TASKS.md for board.sh and set-status.sh (sourced, not executed).
#
# A task is a heading "### Txx Title — `status`" with an optional tail "(depends on T02, T03–T08)",
# "(depends only on T00; ...)" or "(depends on everything)". Tasks without such a tail inherit the dependencies of
# their phase heading "## Phase B: ... (all depend on T02; ...)"; any other "## " heading ends the phase (its tasks
# have no phase and no inherited dependencies). Right under a heading, set-status.sh adds the lines
# "QA: <stamp>: text", "Owner question: <stamp>: text" and "Owner answer: <stamp>: text", newest first.
#
# Dependency lists: ids (also lowercase), ranges with a hyphen, an en dash or an em dash (spaced or not), "..", "to",
# "through" or "thru" (T03–T08, T02 – T04, T00 through T02), separated by commas, "and", "&", "+" or "/". Anything
# else in the clause is reported as a warning, so that a typo cannot silently drop a dependency.
#
# awk runs with LC_ALL=C (byte semantics) so mawk, gawk and BSD awk read the em and en dashes the same way.

BOARD_STATUSES="todo|in progress|testing|needs owner|done"
BOARD_AREAS="exporter attempts queues http batches discovery horizon-ui grafana live ci docs process"

# Prints one tab-separated line per task: id, title, status, phase, dependencies (space-separated ids, may be empty).
# Warnings (unparsed dependency text) go to stderr.
board_parse_tasks() {
    local file="$1"
    [[ -f "$file" ]] || {
        echo "board: task list not found: $file" >&2
        return 1
    }
    LC_ALL=C awk -v statuses="$BOARD_STATUSES" '
        function warn(message) {
            printf "board: warning: %s\n", message > "/dev/stderr"
        }
        # Every id of a list such as "T03–T08, T10 and T12", space-separated. Sets "leftover" to the text that is
        # neither an id, a range nor a separator.
        function expand(clause,    out, rest, id, range, lo, hi, width, k, tmp) {
            out = ""
            leftover = ""
            rest = clause
            sub(/^(all depend|depends) (only )?on/, "", rest)
            while (match(rest, /[Tt][0-9]+/)) {
                leftover = leftover " " substr(rest, 1, RSTART - 1)
                id = "T" substr(rest, RSTART + 1, RLENGTH - 1)
                rest = substr(rest, RSTART + RLENGTH)
                range = ""
                if (match(rest, /^ *(-|–|—|\.\.) *[Tt][0-9]+/) || match(rest, /^ +(to|through|thru) +[Tt][0-9]+/)) {
                    range = substr(rest, RSTART, RLENGTH)
                    rest = substr(rest, RSTART + RLENGTH)
                }
                if (range == "") {
                    out = out " " id
                    continue
                }
                match(range, /[0-9]+$/)
                hi = substr(range, RSTART) + 0
                lo = substr(id, 2) + 0
                width = length(id) - 1
                if (hi < lo) {
                    leftover = leftover " " id range
                    tmp = lo
                    lo = hi
                    hi = tmp
                }
                for (k = lo; k <= hi; k++) {
                    out = out " " sprintf("T%0" width "d", k)
                }
            }
            leftover = leftover " " rest
            gsub(/[,&+\/]/, " ", leftover)
            gsub(/(^| )and( |$)/, " ", leftover)
            gsub(/(^| )and( |$)/, " ", leftover)
            gsub(/[ \t]+/, " ", leftover)
            sub(/^ /, "", leftover)
            sub(/ $/, "", leftover)
            return out
        }
        /^## / {
            phase = ""
            phase_deps = ""
            if ($2 == "Phase") {
                phase = $3
                sub(/:$/, "", phase)
                if (match($0, /all depend (only )?on [^;)]*/)) {
                    phase_deps = expand(substr($0, RSTART, RLENGTH))
                    if (leftover != "") {
                        warn("phase " phase ": unparsed text in \"" substr($0, RSTART, RLENGTH) "\": " leftover)
                    }
                }
            }
            next
        }
        /^### T[0-9]+ / {
            if (match($0, " — `(" statuses ")`") == 0) {
                next
            }
            id = $2
            start = 5 + length(id) + 1
            title = substr($0, start, RSTART - start)
            status = substr($0, RSTART, RLENGTH)
            tail = substr($0, RSTART + RLENGTH)
            sub(/^[^`]*`/, "", status)
            sub(/`$/, "", status)
            deps = phase_deps
            if (match(tail, /depends (only )?on [^;)]*/)) {
                clause = substr(tail, RSTART, RLENGTH)
                if (clause ~ /everything/) {
                    deps = "*"
                } else {
                    deps = expand(clause)
                    if (leftover != "") {
                        warn(id ": unparsed text in \"" clause "\": " leftover)
                    }
                }
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
# Notes belong to the task heading above them; any other heading ends the task.
board_parse_notes() {
    local file="$1"
    LC_ALL=C awk '
        /^#+ / {
            current = ($1 == "###" && $2 ~ /^T[0-9]+$/) ? $2 : ""
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

# The verdict of a QA text: "rejected" (the whole words "rejected", "changes requested" or "not approved"),
# "approved" (the whole word "approved") or nothing. Rejected wins when a text says both.
board_qa_verdict() {
    local text="${1,,}"
    if [[ "$text" =~ (^|[^a-z])(rejected|changes[[:space:]]+requested|not[[:space:]]+approved)([^a-z]|$) ]]; then
        echo rejected
    elif [[ "$text" =~ (^|[^a-z])approved([^a-z]|$) ]]; then
        echo approved
    fi
}

# The round number of a QA text ("round 2 approved by ..." -> 2), or nothing.
board_qa_round() {
    local text="${1,,}"
    if [[ "$text" =~ (^|[^a-z])round[[:space:]]+([0-9]+)([^0-9]|$) ]]; then
        echo "$((10#${BASH_REMATCH[2]}))"
    fi
}

# Counts the QA rounds of every task into QA_ROUNDS and QA_REJECTED: one round per distinct "round N" of the task's
# QA lines that carries a verdict; a round is rejected when any of its lines rejects (the spec and the adversarial
# tester may write one line each). QA lines without "round N" are notes, not rounds.
board_count_rounds() {
    local id kind text round verdict
    local -A seen=()
    declare -gA QA_ROUNDS=() QA_REJECTED=()
    for id in "${TASK_IDS[@]}"; do
        seen=()
        while IFS=$'\t' read -r kind text; do
            [[ "$kind" == "qa" ]] || continue
            round="$(board_qa_round "$text")"
            verdict="$(board_qa_verdict "$text")"
            [[ -n "$round" && -n "$verdict" ]] || continue
            [[ "${seen[$round]:-}" == "rejected" ]] || seen[$round]="$verdict"
        done <<<"${TASK_NOTES[$id]:-}"
        QA_ROUNDS[$id]=0
        QA_REJECTED[$id]=0
        for round in "${!seen[@]}"; do
            QA_ROUNDS[$id]=$((QA_ROUNDS[$id] + 1))
            [[ "${seen[$round]}" != "rejected" ]] || QA_REJECTED[$id]=$((QA_REJECTED[$id] + 1))
        done
    done
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
