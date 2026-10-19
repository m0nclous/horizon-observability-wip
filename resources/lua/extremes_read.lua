-- extremes_read.lua: the duration extremes of the last 150 seconds, merged inside Redis.
--
-- KEYS[1..n]      the min hashes: the current 15-second bucket first, then the previous ones (n = #KEYS / 2, 10)
-- KEYS[n+1..2n]   the max hashes of the same buckets, in the same order
-- ARGV            none
--
-- Each hash maps conn US queue US class => seconds (US = \31). A field can appear in every bucket; the script keeps
-- the smallest value of the min hashes and the largest value of the max hashes, as stored (no float formatting).
-- Missing keys are skipped.
--
-- Returns {field, value, ..., '\30', field, value, ...}: the merged min pairs, the record separator \30, then the
-- merged max pairs.

local half = #KEYS / 2

local function merge(first, last, better)
    local values, numbers, order = {}, {}, {}

    for index = first, last do
        local entries = redis.call('HGETALL', KEYS[index])

        for position = 1, #entries, 2 do
            local field, value = entries[position], entries[position + 1]
            local number = tonumber(value)

            if number then
                if numbers[field] == nil then
                    order[#order + 1] = field
                    values[field], numbers[field] = value, number
                elseif better(number, numbers[field]) then
                    values[field], numbers[field] = value, number
                end
            end
        end
    end

    return values, order
end

local result = {}

local minValues, minOrder = merge(1, half, function (a, b) return a < b end)
for _, field in ipairs(minOrder) do
    result[#result + 1] = field
    result[#result + 1] = minValues[field]
end

result[#result + 1] = '\30'

local maxValues, maxOrder = merge(half + 1, #KEYS, function (a, b) return a > b end)
for _, field in ipairs(maxOrder) do
    result[#result + 1] = field
    result[#result + 1] = maxValues[field]
end

return result
