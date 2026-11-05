local json = require('cjson')
local dict = ngx.shared.production_pacing
local uri = ngx.var.uri
local evidence = uri:match('^/sitemap%-production/evidence/([a-z0-9%-]+)$')
if evidence then
    local events = {}
    for i = 1, (dict:get(evidence .. ':count') or 0) do
        local value = assert(dict:get(evidence .. ':event:' .. i), 'Fixture handler has not settled')
        events[#events + 1] = json.decode(value)
    end
    ngx.header.content_type = 'application/json'
    ngx.say(json.encode({events = events, peak = dict:get(evidence .. ':peak') or 0, active = dict:get(evidence .. ':active') or 0}))
    return
end
local scenario, token, kind, id = uri:match('^/sitemap%-production/([a-z]+)/([a-z0-9%-]+)/([a-z]+)/([0-9]+)$')
if not scenario then return ngx.exit(404) end
id = tonumber(id)
local active = assert(dict:incr(token .. ':active', 1, 0))
local ordinal = assert(dict:incr(token .. ':count', 1, 0))
assert(dict:set(token .. ':peak', math.max(active, dict:get(token .. ':peak') or 0)))
ngx.update_time()
ngx.ctx.production_key = token
ngx.ctx.production_event = {ordinal = ordinal, arrival = ngx.now(), method = ngx.req.get_method(), page_id = id, hop = kind, active = active}
local prefix = 'http://' .. ngx.var.host .. '/sitemap-production/' .. scenario .. '/' .. token
if kind == 'document' then
    if scenario == 'redirectdoc' and id == 0 then
        ngx.header.Location = '/sitemap-production/' .. scenario .. '/' .. token .. '/document/1'
        return ngx.exit(302)
    end
    ngx.header.content_type = 'application/xml'
    if scenario == 'index' and id == 0 then
        ngx.print('<sitemapindex xmlns="http://www.sitemaps.org/schemas/sitemap/0.9"><sitemap><loc>' .. prefix .. '/document/1</loc></sitemap><sitemap><loc>' .. prefix .. '/document/2</loc></sitemap></sitemapindex>')
    else
        local first, last = 1, (scenario == 'large' and 500 or 20)
        if scenario == 'index' then first, last = (id - 1) * 10 + 1, id * 10 end
        ngx.print('<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">')
        for page = first, last do ngx.print('<url><loc>' .. prefix .. '/page/' .. page .. '</loc></url>') end
        ngx.print('</urlset>')
    end
    return
end
if kind ~= 'page' and kind ~= 'target' then return ngx.exit(404) end
local method = ngx.req.get_method()
if scenario == 'slow' then
    ngx.sleep(3)
elseif scenario == 'fallback' then
    ngx.sleep(0.9)
    if method == 'HEAD' then return ngx.exit(405) end
elseif scenario == 'matrix' then
    ngx.sleep(0.05)
    if kind == 'page' and id == 5 then
        ngx.header.Location = '/sitemap-production/' .. scenario .. '/' .. token .. '/target/5'
        return ngx.exit(302)
    end
    if id == 6 then
        ngx.header.Location = 'http://127.0.0.1/sitemap-production/forbidden'
        return ngx.exit(302)
    end
    if id == 4 then return ngx.exit(404) end
    if (id == 3 or id == 8) and method == 'HEAD' then return ngx.exit(405) end
    if id == 8 and method == 'GET' then
        ngx.header.content_length = 3145728
        ngx.print(string.rep('x', 3145728))
        return
    end
else
    ngx.sleep(0.65)
end
ngx.say('ok')
