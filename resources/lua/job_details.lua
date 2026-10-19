--[[
job_details.lua: the attempts and the first exception line of Horizon job hashes, for the final page of the
failed-jobs list only. The payload and the stack trace stay in Redis.

KEYS[1..n]  Horizon job hashes (n <= 100), as the job ids of the page

ARGV[1]  max_exception_bytes  byte cap of the first exception line (1000); 0 = the exception is not read

Returns one {attempts|false, exception|false} pair per key, in KEYS order:
- attempts: the top-level "attempts" of the stored payload as an integer (a JSON number or a numeric string, its
  fraction dropped); false when the hash has no payload or the payload has no such value;
- exception: the stored exception up to its first "\n", cut to max_exception_bytes bytes (a multibyte character
  may be cut; the PHP side scrubs it); false when the hash has no exception or max_exception_bytes is 0.

"attempts": is found with a plain string.find (no patterns: cjson reorders the keys of a released payload, and
the escaped quotes inside string values cannot match), as in queue_state.lua and delayed_jobs.lua: first in the
first and the last WINDOW bytes, where it sits before or after the large "data" value, and in the whole payload
only when neither window has it.
]]

local find, sub, match = string.find, string.sub, string.match

local max_bytes = tonumber(ARGV[1])

if max_bytes == nil then
    return redis.error_reply('ERR job_details: max_exception_bytes must be a number')
end

max_bytes = math.max(0, math.floor(max_bytes))

local WINDOW = 4096
local NEEDLE = '"attempts":'

-- End of the first NEEDLE in the first WINDOW bytes, else of the first in the last WINDOW bytes, else of the first
-- in the whole payload; nil when it does not occur.
local function locate(payload)
    local size = #payload

    if size > 2 * WINDOW then
        local _, last = find(sub(payload, 1, WINDOW + #NEEDLE - 1), NEEDLE, 1, true)

        if last == nil then
            _, last = find(payload, NEEDLE, size - WINDOW + 1, true)
        end

        if last ~= nil then
            return last
        end
    end

    local _, last = find(payload, NEEDLE, 1, true)

    return last
end

local function attempts_of(payload)
    if not payload then
        return false
    end

    local last = locate(payload)

    if last == nil then
        return false
    end

    local digits = match(payload, '^[ \t\r\n]*"?(%-?%d+)', last + 1)

    return digits and tonumber(digits) or false
end

local function first_line(exception)
    if not exception or max_bytes == 0 then
        return false
    end

    local newline = find(exception, '\n', 1, true)
    local stop = newline and newline - 1 or #exception

    return sub(exception, 1, math.min(stop, max_bytes))
end

local result = {}

for index, key in ipairs(KEYS) do
    if max_bytes == 0 then
        result[index] = {attempts_of(redis.call('HGET', key, 'payload')), false}
    else
        local fields = redis.call('HMGET', key, 'payload', 'exception')

        result[index] = {attempts_of(fields[1]), first_line(fields[2])}
    end
end

return result
