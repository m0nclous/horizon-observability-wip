-- new_series.lua: the counter series inside their new-series hold, on the clock of this Redis.
--
-- One EVAL per scrape of the attempts collector, after the counters are read (a series with counts then always has
-- its creation time here, or it was released already). US below is the unit separator \31 of the hash fields.
--
-- KEYS[1]   created   hash   conn US queue US class (attempt series), conn US queue (wait histogram) => creation
--                            time, Unix seconds of this server's clock (written by record_attempt.lua)
--
-- ARGV[1]   the hold, seconds (90)
-- ARGV[2]   '': the server's TIME is the clock; or a Unix time that stands in for it (tests pin the clock)
--
-- A series is held while its age on the server clock is less than the hold, in either direction: a creation time
-- ahead of the clock (the server clock stepped back) is held until the clock is the hold past it, and released at
-- once when it is the hold or more ahead. Every field that is not held is deleted: its series is released for good,
-- so a later step of the server clock can never hold a series again whose counts were exported, and neither can a
-- scrape of another replica (one clock, one decision, kept in Redis). Fields that are not numbers are deleted too.
--
-- TIME and HGETALL are non-deterministic and followed by writes (HDEL): the script asks for effects replication
-- first, which is the default since Redis 5 and the only mode since Redis 7 and in Valkey (there
-- redis.replicate_commands() is a no-op), and required on Redis 6.2 with lua-replicate-commands no.
--
-- A TIME the server refuses (an ACL without @fast) is an error reply, and nothing is released: the attempts
-- collector then holds every series that still has a creation time and marks itself as failed.
--
-- Returns the held fields.

if redis.replicate_commands then
    redis.replicate_commands()
end

local now = tonumber(ARGV[2])
if not now then
    local time = redis.pcall('TIME')

    if type(time) ~= 'table' or not time[1] or not time[2] then
        -- ERR first: phpredis returns other error codes as a RedisException, like a connection error.
        return redis.error_reply('ERR TIME failed: ' .. tostring(type(time) == 'table' and time.err or time))
    end

    now = tonumber(time[1]) + tonumber(time[2]) / 1000000
end

local hold = tonumber(ARGV[1])
local fields = redis.call('HGETALL', KEYS[1])
local held, released = {}, {}

for index = 1, #fields, 2 do
    local created = tonumber(fields[index + 1])

    if created and math.abs(now - created) < hold then
        held[#held + 1] = fields[index]
    else
        released[#released + 1] = fields[index]
    end
end

for first = 1, #released, 500 do
    redis.call('HDEL', KEYS[1], unpack(released, first, math.min(first + 499, #released)))
end

return held
