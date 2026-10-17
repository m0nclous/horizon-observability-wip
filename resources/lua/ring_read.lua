-- ring_read.lua: the attempts of the live ring buffer over the last complete minute (Addendum R4.5, D').
--
-- KEYS[1..n]  the ring buckets live:ring:<unix div 5> to sum: the 12 complete 5-second buckets before the current one
-- ARGV        none
--
-- Each hash maps conn US queue US status => attempts (US = \31), written by record_attempt.lua. A field can appear in
-- every bucket; the script sums it over the buckets. Missing keys are skipped, and so are values that are not
-- numbers.
--
-- Returns {field, sum, ...}: the summed pairs in the order the fields were first seen, each sum an integer reply.

local sums, order = {}, {}

for index = 1, #KEYS do
    local entries = redis.call('HGETALL', KEYS[index])

    for position = 1, #entries, 2 do
        local field = entries[position]
        local count = tonumber(entries[position + 1])

        if count then
            if sums[field] == nil then
                order[#order + 1] = field
                sums[field] = 0
            end

            sums[field] = sums[field] + count
        end
    end
end

local result = {}

for _, field in ipairs(order) do
    result[#result + 1] = field
    result[#result + 1] = sums[field]
end

return result
