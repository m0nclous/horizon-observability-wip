-- lock.lua: compare-and-renew or compare-and-delete of a lease.
--
-- KEYS[1]  the lease key: live:snapshot-lock:<scope hash> (snapshot build) or live:leader (stream leader)
-- ARGV[1]  op: 'renew' or 'release'
-- ARGV[2]  token: the value the holder wrote with SET NX PX
-- ARGV[3]  ttl_ms: the new lifetime for 'renew' (a positive integer); ignored by 'release'
--
-- The lease is only touched while it still holds the token, so a holder whose lease expired and was taken by
-- another process can neither extend nor delete the new holder's lease.
--
-- Returns 1 when the lease was renewed or released, 0 when it is gone or held by another token.

local op, token = ARGV[1], ARGV[2]

if op ~= 'renew' and op ~= 'release' then
    return redis.error_reply('ERR lock: op must be renew or release')
end

if token == nil or token == '' then
    return redis.error_reply('ERR lock: token must not be empty')
end

if redis.call('GET', KEYS[1]) ~= token then
    return 0
end

if op == 'release' then
    return redis.call('DEL', KEYS[1])
end

local ttl = tonumber(ARGV[3])

if ttl == nil or ttl < 1 or ttl ~= math.floor(ttl) then
    return redis.error_reply('ERR lock: ttl_ms must be a positive integer')
end

return redis.call('PEXPIRE', KEYS[1], ttl)
