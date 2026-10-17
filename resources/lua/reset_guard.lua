-- reset_guard.lua: resets the attempt counters after a Redis restart or restore (Arch Q6.10, Appendix D, C21).
--
-- KEYS[1]     meta: hash with run_id, reset_at, dropped, live_push_at, live_push_failures
-- KEYS[2..]   the counter and histogram keys: attempts, duration:buckets, duration:sum, wait:buckets, wait:sum
-- ARGV[1]     now (Unix seconds)
--
-- Runs once per scrape before the counters are read. run_id comes from INFO server, inside the script, so it is the
-- run_id of the node that holds the slot.
-- - INFO fails (e.g. denied by an ACL) or has no run_id: no-op.
-- - meta has no run_id (first scrape, or a lost meta key): store run_id only; nothing is deleted.
-- - the same run_id: no-op.
-- - a different run_id: delete the counter keys and the counter fields of meta (dropped, live_push_failures), then
--   store run_id and reset_at. A restored dataset would otherwise make increase() count old totals again.
--
-- Returns 1 when the counters were deleted, else 0.

local info = redis.pcall('INFO', 'server')
if type(info) ~= 'string' then
    return 0
end

local runId = string.match(info, 'run_id:(%w+)')
if not runId then
    return 0
end

local stored = redis.call('HGET', KEYS[1], 'run_id')

if not stored then
    redis.call('HSET', KEYS[1], 'run_id', runId)
    return 0
end

if stored == runId then
    return 0
end

redis.call('DEL', unpack(KEYS, 2))
redis.call('HDEL', KEYS[1], 'dropped', 'live_push_failures')
redis.call('HSET', KEYS[1], 'run_id', runId)
redis.call('HSET', KEYS[1], 'reset_at', ARGV[1])

return 1
