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
# "through" or "thru" (T03–T08, T02 – T04, T00 through T02), separated by commas, "and", "&", "+" or "/". The clause
# words are read in any case ("(Depends on T01)"). A tail may hold several clauses ("(depends on T01) (depends on
# T05)", "(depends on T01; depends on T05)"); text after a ";" inside the parentheses is commentary ("(depends on
# T01; T05 later)"). Anything else in a clause is reported as a warning, and so is every "### Txx" heading that is
# not a task heading (no title, no em dash before the status, an unknown status such as `Todo` or `in-progress`), a
# tail that names an id without a "depends on" clause ("(depend on T01)", "(after T05)") or after its parentheses
# ("(depends on T01) after T05"), a dependency clause inside the title, a range wider than BOARD_RANGE_MAX ids (only
# its ends are read), a dependency on an id that is no task (a typo such as T0, a task not written yet) and tasks
# whose dependencies form a cycle (two "(depends on everything)" tasks, T03 and T04 waiting for each other): a typo
# must not silently drop a dependency or a task, nor block one for good.
#
# awk runs with LC_ALL=C (byte semantics) so mawk, gawk and BSD awk read the em and en dashes the same way.

BOARD_STATUSES="todo|in progress|testing|needs owner|done"
# The widest dependency range that is expanded: T02–T99999 (a typo) would make every parse slow, under the board lock.
BOARD_RANGE_MAX=1000
BOARD_AREAS="exporter attempts queues http batches discovery horizon-ui grafana live ci docs process"

# Prints one line per task, fields separated by US (\x1f, not a whitespace character, so that an empty phase stays a
# field of its own): id, title, status, phase, dependencies (space-separated ids, may be empty).
# Warnings (unparsed dependency text) go to stderr.
board_parse_tasks() {
    local file="$1"
    [[ -f "$file" ]] || {
        echo "board: task list not found: $file" >&2
        return 1
    }
    LC_ALL=C awk -v statuses="$BOARD_STATUSES" -v range_max="$BOARD_RANGE_MAX" '
        function warn(message) {
            printf "board: warning: %s\n", message > "/dev/stderr"
        }
        function trim(text) {
            gsub(/[ \t]+/, " ", text)
            sub(/^ /, "", text)
            sub(/ $/, "", text)
            return text
        }
        function id_of(k, width) {
            return sprintf("T%0" width "d", k)
        }
        # Every id of a list such as "T03–T08, T10 and T12", space-separated. Sets "leftover" to the text that is
        # neither an id, a range nor a separator, and "wide" to the ranges wider than range_max ids (only their ends
        # are read: a typo such as T02–T99999 must not make the parse quadratic).
        function expand(clause,    out, rest, id, range, lo, hi, width, k, tmp) {
            out = ""
            leftover = ""
            wide = ""
            rest = clause
            if (match(tolower(rest), /^(all depend|depends) (only )?on/)) {
                rest = substr(rest, RLENGTH + 1)
            }
            while (match(rest, /[Tt][0-9]+/)) {
                leftover = leftover " " substr(rest, 1, RSTART - 1)
                id = "T" substr(rest, RSTART + 1, RLENGTH - 1)
                rest = substr(rest, RSTART + RLENGTH)
                range = ""
                if (match(rest, /^ *(-|–|—|\.\.) *[Tt][0-9]+/) || match(tolower(rest), /^ +(to|through|thru) +t[0-9]+/)) {
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
                if (hi - lo >= range_max) {
                    wide = wide " " id range
                    out = out " " id_of(lo, width) " " id_of(hi, width)
                    continue
                }
                for (k = lo; k <= hi; k++) {
                    out = out " " id_of(k, width)
                }
            }
            leftover = leftover " " rest
            gsub(/[,&+\/]/, " ", leftover)
            gsub(/(^| )[Aa][Nn][Dd]( |$)/, " ", leftover)
            gsub(/(^| )[Aa][Nn][Dd]( |$)/, " ", leftover)
            leftover = trim(leftover)
            wide = trim(wide)
            return out
        }
        # The part of a tail between dependency clauses that lies outside their parentheses: after a clause and its
        # ";", the text up to the next ")" is the clause'"'"'s commentary ("(depends on T01; T05 later)").
        function outside_of(text) {
            if (!in_group) {
                return text
            }
            if (match(text, /\)/)) {
                in_group = 0
                return substr(text, RSTART + 1)
            }
            return ""
        }
        # Tarjan'"'"'s strongly connected components over the dependencies (task indexes): a component of more than one
        # task is a dependency cycle, whose tasks can never start.
        function connect(v,    list, count, k, w, size) {
            visit[v] = ++visits
            low[v] = visits
            stack[++top] = v
            stacked[v] = 1
            count = split(final[v], list, " ")
            for (k = 1; k <= count; k++) {
                if (!(list[k] in known)) {
                    continue
                }
                w = known[list[k]]
                if (!(w in visit)) {
                    connect(w)
                    if (low[w] < low[v]) {
                        low[v] = low[w]
                    }
                } else if (stacked[w] && visit[w] < low[v]) {
                    low[v] = visit[w]
                }
            }
            if (low[v] == visit[v]) {
                size = 0
                do {
                    w = stack[top--]
                    stacked[w] = 0
                    component[w] = v
                    size++
                } while (w != v)
                if (size > 1) {
                    cyclic[v] = 1
                }
            }
        }
        # A heading that looks like a task but that the task rule below does not read ("### T05 Five - `todo`",
        # "### T06 Six — `Todo`", "## T07 ...", "### T08 — `todo`" without a title): named, so that a typo does not
        # drop a task from the board unseen.
        /^#+[ \t]*[Tt][0-9]+([^0-9]|$)/ {
            if ($0 !~ /^### T[0-9]+ / || match($0, " — `(" statuses ")`") == 0 || substr($0, 1, RSTART - 1) ~ /^### T[0-9]+[ \t]*$/) {
                heading = $0
                match(heading, /[Tt][0-9]+/)
                known_statuses = statuses
                gsub(/\|/, ", ", known_statuses)
                warn("T" substr(heading, RSTART + 1, RLENGTH - 1) ": not a task heading, so the board does not show it (want \"### Txx Title — `status`\" with an em dash and a status of " known_statuses "): " heading)
                if ($0 ~ /^### /) {
                    next
                }
            }
        }
        /^## / {
            phase = ""
            phase_deps = ""
            if ($2 == "Phase") {
                phase = $3
                sub(/:$/, "", phase)
                if (match(tolower($0), /all depend (only )?on [^;)]*/)) {
                    clause = substr($0, RSTART, RLENGTH)
                    phase_deps = expand(clause)
                    if (leftover != "") {
                        warn("phase " phase ": unparsed text in \"" clause "\": " leftover)
                    }
                    if (wide != "") {
                        warn("phase " phase ": the range \"" wide "\" spans more than " range_max " ids (a typo?), so only its ends are read")
                    }
                } else if (tolower($0) ~ /(^|[^a-z])depend/) {
                    warn("phase " phase ": a dependency clause that is not \"(all depend on ...)\" is not read: " $0)
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
            # Every "depends on ..." clause of the tail counts; "everything" in any of them means every other task.
            rest = tail
            outside = ""
            in_group = 0
            found = 0
            everything = 0
            own = ""
            while (match(tolower(rest), /depends (only )?on [^;)]*/)) {
                at = RSTART
                clause = substr(rest, at, RLENGTH)
                before = substr(rest, 1, at - 1)
                rest = substr(rest, at + RLENGTH)
                before = outside_of(before)
                sub(/\([ \t]*$/, "", before)
                outside = outside " " before
                found = 1
                if (tolower(clause) ~ /everything/) {
                    everything = 1
                } else {
                    own = own " " expand(clause)
                    if (leftover != "") {
                        warn(id ": unparsed text in \"" clause "\": " leftover)
                    }
                    if (wide != "") {
                        warn(id ": the range \"" wide "\" spans more than " range_max " ids (a typo?), so only its ends are read")
                    }
                }
                if (substr(rest, 1, 1) == ";") {
                    in_group = 1
                    rest = substr(rest, 2)
                } else {
                    in_group = 0
                    if (substr(rest, 1, 1) == ")") {
                        rest = substr(rest, 2)
                    }
                }
            }
            outside = trim(outside " " outside_of(rest))
            if (!found) {
                deps = phase_deps
                inherited = (phase_deps != "")
                if (tail ~ /[Tt][0-9]+/ || tolower(tail) ~ /(^|[^a-z])depend/) {
                    warn(id ": \"" trim(tail) "\" names a dependency without a \"(depends on ...)\" clause, so it is not read (the task keeps its phase'"'"'s dependencies)")
                }
            } else {
                deps = everything ? "*" : own
                inherited = 0
                if (outside ~ /[Tt][0-9]+/ || tolower(outside) ~ /(^|[^a-z])depend/) {
                    warn(id ": \"" outside "\" names a dependency outside the parentheses of its \"(depends on ...)\" clause, so it is not read")
                }
            }
            if (tolower(title) ~ /(^|[^a-z])depends? (only )?on / && title ~ /[Tt][0-9]+/) {
                warn(id ": a dependency clause in the title is not read (put it after the status): " title)
            }
            n++
            ids[n] = id
            titles[n] = title
            states[n] = status
            phases[n] = phase
            depends[n] = deps
            from_phase[n] = inherited
        }
        END {
            for (i = 1; i <= n; i++) {
                if (!(ids[i] in known)) {
                    known[ids[i]] = i
                }
            }
            for (i = 1; i <= n; i++) {
                out = ""
                unknown = ""
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
                            if (!(list[j] in known)) {
                                unknown = unknown ", " list[j]
                            }
                        }
                    }
                }
                sub(/^ /, "", out)
                final[i] = out
                # An id that is no task never becomes done: named once per task, or once per phase it came from.
                if (unknown != "") {
                    unknown = substr(unknown, 3)
                    key = from_phase[i] ? "phase " phases[i] : ids[i]
                    if (!(key in named)) {
                        named[key] = 1
                        plural = (unknown ~ /, /)
                        warn(key ": depends on " unknown ", which " (plural ? "are no tasks" : "is no task") " of the list (a typo? a task not written yet?), so " (from_phase[i] ? "its tasks stay" : "it stays") " blocked until " (plural ? "such tasks are" : "such a task is") " done")
                    }
                }
            }
            for (i = 1; i <= n; i++) {
                if (!(i in visit)) {
                    connect(i)
                }
            }
            for (i = 1; i <= n; i++) {
                if (!(i in cyclic)) {
                    continue
                }
                members = ""
                for (j = 1; j <= n; j++) {
                    if (component[j] == i) {
                        members = members ", " ids[j]
                    }
                }
                warn(substr(members, 3) ": their dependencies form a cycle (each waits for another of them, maybe through \"(depends on everything)\"), so none of them can start; drop one of the dependencies")
            }
            for (i = 1; i <= n; i++) {
                printf "%s\037%s\037%s\037%s\037%s\n", ids[i], titles[i], states[i], phases[i], final[i]
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
    while IFS=$'\x1f' read -r id title status phase deps; do
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

# The verdict of a QA text: "approved", "rejected" or nothing. It is the word right after the first "round N" (an
# optional "QA verdict:" between them), else right after "QA verdict:", else the text's first word: "approved", or
# "rejected", "changes requested" or "not approved". Words further on do not count, so that "round 1 approved ...,
# no changes requested" or "round 2 approved: the findings that were rejected are fixed" stay approvals.
board_qa_verdict() {
    local text="${1,,}" rest
    local verdict='^[^a-z0-9]*(qa[[:space:]]+verdict[^a-z0-9]*)?(approved|rejected|changes[[:space:]]+requested|not[[:space:]]+approved)([^a-z]|$)'
    if [[ "$text" =~ (^|[^a-z])round[[:space:]]+[0-9]+(.*)$ ]]; then
        rest="${BASH_REMATCH[2]}"
        # The rest of the line after the first "round N" only.
        if [[ "$rest" =~ ^[0-9]*(.*)$ ]]; then
            rest="${BASH_REMATCH[1]}"
        fi
    elif [[ "$text" =~ (^|[^a-z])qa[[:space:]]+verdict(.*)$ ]]; then
        rest="qa verdict${BASH_REMATCH[2]}"
    else
        rest="$text"
    fi
    if [[ "$rest" =~ $verdict ]]; then
        case "${BASH_REMATCH[2]}" in
            approved) echo approved ;;
            *) echo rejected ;;
        esac
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
