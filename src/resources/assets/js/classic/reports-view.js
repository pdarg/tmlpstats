import { objectAssign } from '../reusable/ponyfill'
import Api from '../api'

import Reports from './reports-generated'

const { $, console } = window

const debug = (...x) => {
    console.log(...x)
}

window.showReportView = function(config) {
    const loaderHTML = $('#loader').html()

    const fullReport = Reports[config.report]
    const target = $(config.target)
    const reportApi = Api[config.report + 'Report']

    var loadQueue = []
    var loaded = {}

    /// SHOW REPORT OF CHOOSING. ACTUAL AJAX STUFF
    function showReport(cid) {
        const report = fullReport.children[cid]
        if (!report) {
            debug('avoiding report', cid)
            return 'no'
        } else if (report.type == 'grouping')  {
            debug(cid + ' is grouping, finding active report')
            return showReport($('#' + cid).find('.grouping .btn-primary').data('cid'))
        } else {
            debug('would show report', report.id, report.name)
            queueLoad(cid)
            debug('order is now', loadQueue.join(','))
        }
    }

    /// Manage Load Queue
    function queueLoad(id) {
        const idx = loadQueue.indexOf(id)
        if (idx != -1) {
            loadQueue.splice(idx, 1)
        }
        loadQueue.push(id)
    }

    var NUM_LOAD = 1

    function loadNext() {
        var pages = []
        for (var i = 0; i < NUM_LOAD; i++) {
            if (!loadQueue.length) {
                break
            }
            let page = loadQueue.pop()
            if (!loaded[page]) {
                pages.push(page)
            }
        }

        if (!pages.length) {
            return
        }
        debug('about to actually load', pages.join(', '))
        const start = new Date().getTime()
        const params = objectAssign({pages: pages}, config.params)
        reportApi.getReportPages(params).then(function(data) {
            const elapsed = new Date().getTime() - start
            // if no error, and elapsed time was under 5 seconds, then increase number of pages requested per request.
            if (elapsed < 5000 && NUM_LOAD < 6) {
                NUM_LOAD++
            }
            pages.forEach((page) => {
                debug('loaded page', page)
                loaded[page] = true
                const container = target.find(`#${page}-content`)
                container.html(data.pages[page])
                window.updateDates(container)
                window.initDataTables(undefined, undefined, container)
            })

            setTimeout(loadNext, 200)
            return data
        }, function() {
            debug('pages failed', pages)
            setTimeout(loadNext, 300)
        })
    }



    var tabs = '<div><ul id="tabs" class="nav nav-tabs tabs-top brief-tabs" data-tabs="tabs">'
    var content = '<div><div class="tab-content">'
    fullReport.root.forEach((id) => {
        const report = fullReport.children[id]
        const className = (report.id == window.location.hash.substr(1)) ? 'active': ''
        const rtype = (report.type == 'report')? report.id : ''
        const tabLabel = responsiveLabel(report)
        tabs += `<li class="${className}"><a href="#${id}" data-toggle="tab" data-rtype="${rtype}">${tabLabel}</a></li>`

        content += `<div class="tab-pane ${className}" id="${id}"><h3>${report.name}</h3>`
        if (report.type == 'grouping') {
            var subtabs = '<div class="btn-group grouping" role="group">'
            var blah = '</div><div class="subtabContent">'
            report.children.forEach((cid) => {
                queueLoad(cid)
                const report = fullReport.children[cid]
                subtabs += `<button data-cid="${cid}" type="button" class="btn btn-default">${report.name}</button>`
                blah += `<div id="${cid}-content">${loaderHTML}</div>`
            })
            content += subtabs + blah + '</div>'
        } else {
            queueLoad(id)
            content += `<div id="${id}-content">${loaderHTML}</div>`
        }
        content += '</div>'
    })
    content += '</div></div>'
    tabs += '</ul></div>'

    target.append($(tabs)).append($(content))


    target.on('click', '.grouping button', function(e) {
        var elem = $(e.target)
        const cid = elem.data('cid')
        elem.siblings().addClass('btn-default').removeClass('btn-primary')
        elem.removeClass('btn-default').addClass('btn-primary')
        const c = $(`#${cid}-content`)
        c.show()
        c.siblings().hide()
        showReport(cid)
    })

    const navTabs = target.find('.nav-tabs')
    navTabs.stickyTabs() // TODO see if we can eliminate this soon
    navTabs.on('click', 'li a', function(e) {
        const elem = $(e.target)
        const cid = elem.data('rtype')
        if (cid) {
            showReport(cid)
        }
    })

    target.find('.grouping button:first-child').click()
    if (showReport(window.location.hash.substr(1)) == 'no') {
        // If we get here, there was no hash or an invalid hash. Choose the first tab.
        navTabs.find('li:first-child a').click()
    }

    loadNext()
    setTimeout(loadNext, 100) // Run a parallel 'thread' slightly offset in time.
}

/** Generate tab label HTML for a report if shortName is set */
function responsiveLabel(report) {
    return (report.shortName)? `<span class="long">${report.name}</span><span class="brief">${report.shortName}</span>` : report.name
}
