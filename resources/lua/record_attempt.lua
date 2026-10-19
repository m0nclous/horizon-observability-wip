-- record_attempt.lua: records one finished job attempt.
--
-- One EVAL per attempt on the package storage connection. Every key arrives in KEYS (the connection prefix applies)
-- and every key carries the {horizon-observability} hash tag, so all of them sit in one Redis Cluster slot. US below
-- is the unit separator \31 that joins label values in hash fields.
--
-- KEYS[1]   attempts                    hash    conn US queue US class US status => count
-- KEYS[2]   duration:buckets            hash    conn US queue US class US le => non-cumulative count
-- KEYS[3]   duration:sum                hash    conn US queue US class => seconds
-- KEYS[4]   wait:buckets                hash    conn US queue US le => non-cumulative count
-- KEYS[5]   wait:sum                    hash    conn US queue => seconds
-- KEYS[6]   extremes:<unix div 15>:min  hash    conn US queue US class => seconds, TTL ARGV[10]
-- KEYS[7]   extremes:<unix div 15>:max  hash    conn US queue US class => seconds, TTL ARGV[10]
-- KEYS[8]   job_classes                 zset    class => last seen (Unix seconds)
-- KEYS[9]   meta                        hash    dropped => count
-- KEYS[10]  live:ring:<unix div 5>      hash    conn US queue US status => count, TTL ARGV[13]; always passed
-- KEYS[11]  created                     hash    conn US queue US class, conn US queue => creation time (Unix
--                                               seconds of this server's clock)
-- KEYS[12]  ready_at:<id>               string  optional (legacy stacks): readiness of a delayed job; read, deleted
--
-- ARGV[1]   connection                  ARGV[2]   queue                ARGV[3]   job class       ARGV[4]   status
-- ARGV[5]   duration in seconds, or ''  ARGV[6]   duration bucket le, or ''
-- ARGV[7]   startedAt (Unix seconds), or '': the wait is recorded only when it is set
-- ARGV[8]   readyAt (Unix seconds), or ''
-- ARGV[9]   wait bucket bounds: le values, ascending, comma-separated; above the last one the bucket is +Inf
-- ARGV[10]  extremes TTL (300)          ARGV[11]  max job classes      ARGV[12]  class idle TTL (2592000)
-- ARGV[13]  ring TTL (180, or 0 = no ring write)                       ARGV[14]  now (worker clock, Unix s)
-- ARGV[15]  dropped attempts to add to meta.dropped                    ARGV[16]  overflow class label, (other)
-- ARGV[17]  the attempt statuses, comma-separated (completed,released,retried,failed,timed_out)
-- ARGV[18]  '': the creation times are this server's TIME; or a Unix time that stands in for it (tests pin the
--           server clock, ServerClock)
--
-- Steps: 1. class admission (refresh a known class; admit a new one below the cap; when full, prune classes idle
-- for longer than the idle TTL and retry; otherwise record the attempt as the overflow label); 2. the attempt
-- counter, and the creation time of a (connection, queue, class) whose first attempt this is; 3. the duration
-- bucket, sum and extremes; 4. the wait of a first attempt, max(readyAt, ready_at key) .. startedAt, at least 0, and
-- the creation time of a queue whose first wait this is; 5. meta.dropped; 6. the live ring buffer.
--
-- Creation times: the attempts collector exports a new series as 0 for its first seconds, so Prometheus sees
-- every counter series start at 0 and increase() counts its first attempts. A creation time is written only together
-- with the first count of its series, never for a series that already has counts, so data written before it existed
-- is never held back. It is this server's TIME, not the worker's clock (ARGV[14]): new_series.lua measures the age
-- with TIME again, so no worker, replica or scraper clock enters the hold. TIME is read at most once per call, only
-- when a creation time is written. It is non-deterministic and followed by writes: the script asks for effects
-- replication first (the default since Redis 5, the only mode since Redis 7 and in Valkey, where the call is a
-- no-op; required on Redis 6.2 with lua-replicate-commands no). A TIME the server refuses (an ACL without @fast)
-- writes no creation time, so the attempt is still recorded and its series is not held (new_series.lua fails on
-- the same ACL, which marks the attempts collector as failed).
--
-- Returns the effective job class: ARGV[3], or ARGV[16] when the class cap is full.

if redis.replicate_commands then
    redis.replicate_commands()
end

local US = '\31'
local connection, queue, class, status = ARGV[1], ARGV[2], ARGV[3], ARGV[4]
local now = tonumber(ARGV[14])
local createdAt

-- The creation time of a new series: the pinned time, or this server's TIME (seconds.microseconds), read once;
-- false when the server refuses TIME.
local function creationTime()
    if createdAt == nil then
        if ARGV[18] and ARGV[18] ~= '' then
            createdAt = ARGV[18]
        else
            local time = redis.pcall('TIME')

            if type(time) == 'table' and time[1] and time[2] then
                createdAt = time[1] .. '.' .. string.format('%06d', tonumber(time[2]))
            else
                createdAt = false
            end
        end
    end

    return createdAt
end

-- 1. Class admission.
if class ~= ARGV[16] then
    if redis.call('ZSCORE', KEYS[8], class) then
        redis.call('ZADD', KEYS[8], ARGV[14], class)
    else
        local max = tonumber(ARGV[11])

        if redis.call('ZCARD', KEYS[8]) >= max then
            redis.call('ZREMRANGEBYSCORE', KEYS[8], '-inf', '(' .. tostring(now - tonumber(ARGV[12])))
        end

        if redis.call('ZCARD', KEYS[8]) < max then
            redis.call('ZADD', KEYS[8], ARGV[14], class)
        else
            class = ARGV[16]
        end
    end
end

local jobField = connection .. US .. queue .. US .. class

-- 2. The attempt counter; a series without any status yet gets its creation time.
if redis.call('HINCRBY', KEYS[1], jobField .. US .. status, 1) == 1 then
    local created = true

    for other in string.gmatch(ARGV[17], '[^,]+') do
        if other ~= status and redis.call('HEXISTS', KEYS[1], jobField .. US .. other) == 1 then
            created = false
            break
        end
    end

    if created and creationTime() then
        redis.call('HSET', KEYS[11], jobField, creationTime())
    end
end

-- 3. Duration: histogram bucket and sum, then compare-and-set of the extremes of the 15-second bucket.
if ARGV[5] ~= '' then
    local duration = tonumber(ARGV[5])

    redis.call('HINCRBY', KEYS[2], jobField .. US .. ARGV[6], 1)
    redis.call('HINCRBYFLOAT', KEYS[3], jobField, ARGV[5])

    local min = tonumber(redis.call('HGET', KEYS[6], jobField))
    if not min or duration < min then
        redis.call('HSET', KEYS[6], jobField, ARGV[5])
    end

    local max = tonumber(redis.call('HGET', KEYS[7], jobField))
    if not max or duration > max then
        redis.call('HSET', KEYS[7], jobField, ARGV[5])
    end

    redis.call('EXPIRE', KEYS[6], ARGV[10])
    redis.call('EXPIRE', KEYS[7], ARGV[10])
end

-- 4. Wait of a first attempt.
if ARGV[7] ~= '' then
    local ready = tonumber(ARGV[8])

    if KEYS[12] then
        local side = tonumber(redis.call('GET', KEYS[12]))
        redis.call('DEL', KEYS[12])

        if side and (not ready or side > ready) then
            ready = side
        end
    end

    if ready then
        local wait = tonumber(ARGV[7]) - ready
        if wait < 0 then
            wait = 0
        end

        local le = '+Inf'
        for bound in string.gmatch(ARGV[9], '[^,]+') do
            if wait <= tonumber(bound) then
                le = bound
                break
            end
        end

        local queueField = connection .. US .. queue
        if redis.call('HEXISTS', KEYS[5], queueField) == 0 and creationTime() then
            redis.call('HSET', KEYS[11], queueField, creationTime())
        end

        redis.call('HINCRBY', KEYS[4], queueField .. US .. le, 1)
        redis.call('HINCRBYFLOAT', KEYS[5], queueField, wait)
    end
elseif KEYS[12] then
    redis.call('DEL', KEYS[12])
end

-- 5. Attempts dropped while the worker's circuit breaker was open.
local dropped = tonumber(ARGV[15])
if dropped and dropped > 0 then
    redis.call('HINCRBY', KEYS[9], 'dropped', dropped)
end

-- 6. Live ring buffer.
local ringTtl = tonumber(ARGV[13])
if ringTtl and ringTtl > 0 then
    redis.call('HINCRBY', KEYS[10], connection .. US .. queue .. US .. status, 1)
    redis.call('EXPIRE', KEYS[10], ringTtl)
end

return class
