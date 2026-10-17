--[[
delayed_jobs.lua: not-yet-due delayed jobs of one Laravel Redis queue as light rows, so the payloads stay in Redis
(Arch Q2.1, C13; Appendix D).

KEYS[1]  queues:<q>:delayed   zset of delayed payloads, score = the time the job becomes available (Unix seconds)

ARGV[1]  now     Unix seconds (float), captured once per request; an entry with a score <= now is due (it counts
                 as pending, like in queue_state.lua) and is not listed
ARGV[2]  offset  number of not-yet-due entries to skip
ARGV[3]  count   number of entries to read (the PHP side reads 500 at a time)

Returns {read, row, row, ...}, ascending by score:
- read: the number of zset entries read; below count, the zset has no further not-yet-due entry;
- one row {uuid, displayName, attempts, pushedAt|false, score} per entry whose payload is a JSON object (other
  entries are skipped but counted in read):
  - uuid, displayName: the raw JSON string content (escapes not decoded); '' when missing or not a string;
  - attempts: the top-level "attempts" as an integer (a JSON number or a numeric string, its fraction dropped);
    0 when missing;
  - pushedAt: the raw text of Horizon's "pushedAt" (a JSON string's content or a number); false when missing or of
    another type;
  - score: the score as Redis prints it.

Fields are found with a plain string.find of the top-level keys "uuid":, "displayName":, "attempts": and
"pushedAt": (no patterns: cjson reorders the keys of a released payload, and the escaped quotes inside string
values cannot match), as in queue_state.lua.
]]

local find, sub, byte, match = string.find, string.sub, string.byte, string.match

local now = ARGV[1]
local offset = tonumber(ARGV[2])
local count = tonumber(ARGV[3])

if tonumber(now) == nil or offset == nil or count == nil then
    return redis.error_reply('ERR delayed_jobs: now, offset and count must be numbers')
end

offset = math.max(0, math.floor(offset))
count = math.max(0, math.floor(count))

-- Index of the quote that closes the JSON string whose content starts at `from`; nil when it is unterminated.
local function closing_quote(payload, from)
    local position = from

    while true do
        local quote = find(payload, '"', position, true)

        if quote == nil then
            return nil
        end

        local before = quote - 1

        while before >= from and byte(payload, before) == 92 do
            before = before - 1
        end

        if (quote - 1 - before) % 2 == 0 then
            return quote
        end

        position = quote + 1
    end
end

-- The first "<key>": of the payload as 'string', its raw content, or 'number', its text; nil for another type
-- or a missing key.
local function value_of(payload, key)
    local _, last = find(payload, '"' .. key .. '":', 1, true)

    if last == nil then
        return nil
    end

    local start = match(payload, '^[ \t\r\n]*()', last + 1)

    if byte(payload, start) == 34 then
        local quote = closing_quote(payload, start + 1)

        if quote == nil then
            return nil
        end

        return 'string', sub(payload, start + 1, quote - 1)
    end

    local number = match(payload, '^%-?%d[%d.eE+%-]*', start)

    if number == nil then
        return nil
    end

    return 'number', number
end

local function text_of(payload, key)
    local kind, raw = value_of(payload, key)

    return kind == 'string' and raw or ''
end

local function attempts_of(payload)
    local _, raw = value_of(payload, 'attempts')
    local digits = raw and match(raw, '^[ \t\r\n]*(%-?%d+)')

    return digits and tonumber(digits) or 0
end

local entries = redis.call('ZRANGEBYSCORE', KEYS[1], '(' .. now, '+inf', 'WITHSCORES', 'LIMIT', offset, count)
local result = {#entries / 2}

for index = 1, #entries, 2 do
    local payload = entries[index]

    if match(payload, '^[ \t\r\n]*{') then
        local _, pushed = value_of(payload, 'pushedAt')

        result[#result + 1] = {
            text_of(payload, 'uuid'),
            text_of(payload, 'displayName'),
            attempts_of(payload),
            pushed or false,
            entries[index + 1],
        }
    end
end

return result
