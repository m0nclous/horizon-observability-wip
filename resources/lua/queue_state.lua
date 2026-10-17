--[[
queue_state.lua: the state of one Laravel Redis queue (Arch Q6.3, Q6.11, C8, C12; Appendix D, Addendum D').

KEYS[1]  queues:<q>            list of ready payloads, head first
KEYS[2]  queues:<q>:reserved   zset of the payloads workers hold
KEYS[3]  queues:<q>:delayed    zset of delayed payloads, score = available at (Unix seconds)

ARGV[1]  now                   Unix seconds (float); a delayed payload with score <= now is due
ARGV[2]  scan_limit            head payloads counted by class in full mode; the readiness search looks at
                               max(1, scan_limit) head payloads, at most 20 in live mode (0 = head only)
ARGV[3]  reserved_scan_limit   reserved payloads counted by class (1000; 0 = none)
ARGV[4]  max_bytes             byte cap of the head scan (16777216; the live snapshot passes 1048576)
ARGV[5]  mode                  'full' (queues collector) or 'live' (live snapshot: no pending_pairs, the head scan
                               stops at the first fresh payload)

Returns {pending_len, reserved_total, delayed_total, delayed_due, earliest_due_score|false,
         first_fresh_ready_at|false, scanned, pending_pairs, reserved_pairs}:
- earliest_due_score: the lowest score of a due delayed payload as Redis prints it; false when none is due;
- first_fresh_ready_at: max(pushedAt, createdAt + delay) of the first scanned payload with attempts == 0, as a
  decimal string (%.17g, so the PHP side reads the exact double); false when no scanned payload is a first
  attempt or the readiness of the first one is unknown (no usable timestamps);
- scanned: head payloads read; the head scan stops at max_bytes (the payload that reaches the cap is included);
- pending_pairs, reserved_pairs: flat {displayName, count, ...}, the displayName as the raw JSON string content
  (escapes not decoded; '' when it is missing or not a string); reserved_pairs is empty when
  reserved_scan_limit is 0, and counts at most reserved_scan_limit payloads and 16 MB of them (the payload that
  reaches the cap is included).

Fields are found with a plain string.find of the top-level keys "displayName":, "attempts":, "pushedAt":,
"createdAt": and "delay": (no patterns: cjson reorders the keys of a released payload, and the escaped quotes
inside string values cannot match). A key that occurs more than once (the data array of a string job, or a
payload hook, has a key of the same name) is read from the payload decoded with cjson instead. The value rules
equal Queues\PayloadClock (tests/Contract/ReadinessParityTest): pushedAt is a JSON number or a numeric string (PHP
is_numeric); createdAt and delay count only as JSON integers in the int64 range (delay:null = no delay); attempts
that is absent, not numeric or truncates to 0 is a first attempt.
]]

local find, sub, byte, match, format = string.find, string.sub, string.byte, string.match, string.format

local now = ARGV[1]
local scan_limit = math.max(0, math.floor(tonumber(ARGV[2]) or 0))
local reserved_limit = math.max(0, math.floor(tonumber(ARGV[3]) or 0))
local max_bytes = math.max(1, tonumber(ARGV[4]) or 16777216)
local mode = ARGV[5] or 'full'

if tonumber(now) == nil then
    return redis.error_reply('ERR queue_state: now must be a number')
end

if mode ~= 'full' and mode ~= 'live' then
    return redis.error_reply('ERR queue_state: mode must be full or live')
end

local live = mode == 'live'
local INT64_MAX, INT64_MIN = '9223372036854775807', '9223372036854775808'

-- Index of the quote that closes the JSON string whose content starts at `from`; nil when it is unterminated.
local function closing_quote(text, from)
    local position = from

    while true do
        local quote = find(text, '"', position, true)

        if quote == nil then
            return nil
        end

        local before = quote - 1

        while before >= from and byte(text, before) == 92 do
            before = before - 1
        end

        if (quote - 1 - before) % 2 == 0 then
            return quote
        end

        position = quote + 1
    end
end

-- The value of a top-level key: 'string', raw content (escapes kept); 'number', text; 'text', a decoded string;
-- 'value', a decoded number; nil when the key is absent or holds another type. `payload` is {text, decoded}: the
-- payload decoded with cjson only when a key occurs twice (false when it is not valid JSON).
local function value_of(payload, key)
    local text = payload[1]
    local needle = '"' .. key .. '":'
    local _, last = find(text, needle, 1, true)

    if last == nil then
        return nil
    end

    if find(text, needle, last + 1, true) ~= nil then
        if payload[2] == nil then
            local ok, object = pcall(cjson.decode, text)
            payload[2] = ok and type(object) == 'table' and object or false
        end

        if payload[2] ~= false then
            local value = payload[2][key]

            if type(value) == 'string' then
                return 'text', value
            end

            if type(value) == 'number' then
                return 'value', value
            end

            return nil
        end
    end

    local start = match(text, '^[ \t\r\n]*()', last + 1)

    if byte(text, start) == 34 then
        local quote = closing_quote(text, start + 1)

        if quote == nil then
            return nil
        end

        return 'string', sub(text, start + 1, quote - 1)
    end

    local number = match(text, '^[-+%d.eE]+', start)

    if number == nil then
        return nil
    end

    return 'number', number
end

-- JSON string content with its escapes decoded; nil when the escapes are invalid.
local function decoded(raw)
    if find(raw, '\\', 1, true) == nil then
        return raw
    end

    local ok, text = pcall(cjson.decode, '"' .. raw .. '"')

    if ok and type(text) == 'string' then
        return text
    end

    return nil
end

-- PHP 8 is_numeric() of a string: optional surrounding whitespace, a sign, digits with an optional fraction (or a
-- fraction alone) and an optional exponent; no hex, no INF or NAN. The number, or nil.
local function numeric_string(text)
    if text == nil then
        return nil
    end

    local body = match(text, '^[ \t\n\r\v\f]*(.-)[ \t\n\r\v\f]*$')
    local mantissa = match(body, '^[+-]?([%d.]+)[eE][+-]?%d+$') or match(body, '^[+-]?([%d.]+)$')

    if mantissa == nil or (match(mantissa, '^%d+%.?%d*$') == nil and match(mantissa, '^%.%d+$') == nil) then
        return nil
    end

    return tonumber(body)
end

-- A JSON number or numeric string (PHP is_numeric after json_decode).
local function number_of(payload, key)
    local kind, raw = value_of(payload, key)

    if kind == 'number' then
        return tonumber(raw)
    end

    if kind == 'value' then
        return raw
    end

    if kind == 'string' then
        return numeric_string(decoded(raw))
    end

    if kind == 'text' then
        return numeric_string(raw)
    end

    return nil
end

-- A JSON integer in the int64 range (PHP is_int after json_decode). A decoded number counts when it is integral.
local function integer_of(payload, key)
    local kind, raw = value_of(payload, key)

    if kind == 'value' then
        if raw == math.floor(raw) and raw >= -9223372036854775808 and raw < 9223372036854775808 then
            return raw
        end

        return nil
    end

    if kind ~= 'number' then
        return nil
    end

    local sign, digits = match(raw, '^(-?)(%d+)$')

    if digits == nil or #digits > 19 or (#digits == 19 and digits > (sign == '-' and INT64_MIN or INT64_MAX)) then
        return nil
    end

    return tonumber(raw)
end

local function finite(value)
    return value ~= nil and value == value and value ~= math.huge and value ~= -math.huge
end

-- PayloadClock::isFirstAttempt(): attempts absent, not numeric or 0 after PHP's (int) truncation.
local function first_attempt(payload)
    local attempts = number_of(payload, 'attempts')

    return attempts == nil or not finite(attempts) or (attempts > -1 and attempts < 1)
end

-- PayloadClock::readyAt(): max(pushedAt, createdAt + delay); createdAt is the base without pushedAt.
local function ready_at(payload)
    local pushed = number_of(payload, 'pushedAt')
    local created = integer_of(payload, 'createdAt')
    local delay = integer_of(payload, 'delay')
    local base = pushed or created

    if base == nil then
        return nil
    end

    if created ~= nil and delay ~= nil then
        return math.max(base, created + delay)
    end

    return base
end

-- The raw JSON string content of the displayName ('' when it is missing or not a string).
local function display_name(payload)
    local kind, raw = value_of(payload, 'displayName')

    if kind == 'string' then
        return raw
    end

    if kind == 'text' then
        return sub(cjson.encode(raw), 2, -2)
    end

    return ''
end

local function flat(counts)
    local list = {}

    for name, count in pairs(counts) do
        list[#list + 1] = name
        list[#list + 1] = count
    end

    return list
end

-- Head scan: class counts of the first scan_limit payloads (full mode) and the readiness of the first fresh one.
local length = redis.call('LLEN', KEYS[1])
local count_limit = scan_limit
local ready_limit = math.max(1, scan_limit)

if live then
    count_limit = 0
    ready_limit = math.max(1, math.min(scan_limit, 20))
end

local window = math.min(length, math.max(count_limit, ready_limit))
local pending, scanned, bytes, largest, index = {}, 0, 0, 0, 0
local found, fresh = false, nil

while index < window and bytes < max_bytes and not (live and found) do
    -- Chunks sized by the largest payload so far keep the Lua memory near max_bytes; live mode reads one at a time.
    local chunk = 1

    if not live and largest > 0 then
        chunk = math.floor((max_bytes - bytes) / largest)
    end

    chunk = math.max(1, math.min(chunk, 100, window - index))

    local items = redis.call('LRANGE', KEYS[1], index, index + chunk - 1)

    if #items == 0 then
        break
    end

    for _, text in ipairs(items) do
        if bytes >= max_bytes or (live and found) then
            break
        end

        local payload = {text}
        local size = #text
        scanned = scanned + 1
        bytes = bytes + size

        if size > largest then
            largest = size
        end

        if index < count_limit then
            local name = display_name(payload)
            pending[name] = (pending[name] or 0) + 1
        end

        if not found and index < ready_limit and first_attempt(payload) then
            found = true
            fresh = ready_at(payload)
        end

        index = index + 1
    end
end

-- Reserved payloads by class: at most reserved_limit of them and RESERVED_MAX_BYTES (the payload that reaches the cap
-- is included; the rest are unscanned), in chunks sized like the head scan's.
local RESERVED_MAX_BYTES = 16777216
local reserved_total = redis.call('ZCARD', KEYS[2])
local reserved = {}
local reserved_window = math.min(reserved_total, reserved_limit)
local offset, reserved_bytes, reserved_largest = 0, 0, 0

while offset < reserved_window and reserved_bytes < RESERVED_MAX_BYTES do
    local chunk = 1

    if reserved_largest > 0 then
        chunk = math.floor((RESERVED_MAX_BYTES - reserved_bytes) / reserved_largest)
    end

    chunk = math.max(1, math.min(chunk, 100, reserved_window - offset))

    local items = redis.call('ZRANGE', KEYS[2], offset, offset + chunk - 1)

    if #items == 0 then
        break
    end

    for _, text in ipairs(items) do
        if reserved_bytes >= RESERVED_MAX_BYTES then
            break
        end

        local size = #text
        reserved_bytes = reserved_bytes + size

        if size > reserved_largest then
            reserved_largest = size
        end

        local name = display_name({text})
        reserved[name] = (reserved[name] or 0) + 1
        offset = offset + 1
    end
end

-- Delayed payloads; only a worker's pop() of this queue moves due ones to the list, so they can wait here.
local delayed_total = redis.call('ZCARD', KEYS[3])
local due, earliest = 0, false

if delayed_total > 0 then
    local first = redis.call('ZRANGEBYSCORE', KEYS[3], '-inf', now, 'WITHSCORES', 'LIMIT', 0, 1)

    if first[2] ~= nil then
        earliest = first[2]
        due = redis.call('ZCOUNT', KEYS[3], '-inf', now)
    end
end

local fresh_text = false

if finite(fresh) then
    fresh_text = format('%.17g', fresh)
end

return {length, reserved_total, delayed_total, due, earliest, fresh_text, scanned, flat(pending), flat(reserved)}
