import React, { Component, PropTypes } from 'react'
import { defaultMemoize } from 'reselect'

import { TabbedReport } from '../tabbed_report/components'
import { connectRedux, delayDispatch } from '../../reusable/dispatch'
import { filterReportFlags, makeTwoTabParamsSelector } from '../tabbed_report/util'
import { reportConfigData } from '../data'
import ReportsMeta from '../meta'

import { loadConfig } from './actions'
import { reportData, GlobalReportKey } from './data'

const DEFAULT_FLAGS = {afterClassroom2: true}

@connectRedux()
export class GlobalReport extends Component {
    static mapStateToProps() {
        const getStorageKey = defaultMemoize(params => new GlobalReportKey(params))

        return (state, ownProps) => {
            const storageKey = getStorageKey(ownProps.params)
            const reportConfig = reportConfigData.selector(state).get(storageKey)
            const reportRoot = reportData.opts.findRoot(state)
            return {
                storageKey, reportConfig,
                lookupsLoad: state.lookups.loadState,
                pageData: reportRoot.data
            }
        }
    }

    static propTypes = {
        storageKey: PropTypes.instanceOf(GlobalReportKey),
        reportConfig: PropTypes.object,
        lookupsLoad: PropTypes.object,
        pageData: PropTypes.object
    }

    constructor(props) {
        super(props)
        this.makeTabParams = makeTwoTabParamsSelector()
        this.componentWillReceiveProps(props)
    }

    reportUriBase() {
        const { regionAbbr, reportingDate } = this.props.params
        return `/reports/regions/${regionAbbr}/${reportingDate}`
    }

    componentWillReceiveProps(nextProps) {
        const { storageKey, reportConfig, lookupsLoad, dispatch, params } = nextProps
        if (params !== this.props.params || storageKey != this._savedKey) {
            this.showReport(nextProps.params.tab2 || nextProps.params.tab1)
            this._savedKey = storageKey
        }
        if (!reportConfig) {
            if (lookupsLoad.available) {
                dispatch(loadConfig(storageKey))
            }
        } else if (reportConfig !== this.props.reportConfig) {
            this.fullReport = filterReportFlags(ReportsMeta['Global'], DEFAULT_FLAGS)
        }
    }

    reportUri(parts) {
        let tabParts = parts.join('/')
        return `${this.reportUriBase()}/${tabParts}`
    }

    showReport(reportId) {
        if (!this.fullReport) {
            setTimeout(() => this.showReport(reportId), 200)
            return
        }
        const report = this.fullReport[reportId]
        if (!report) {
            alert('Unknown report page: ' + reportId)
        } else if (report.type != 'grouping') {
            const { regionAbbr, reportingDate } = this.props.params
            delayDispatch(this, reportData.loadReport(reportId, {region: regionAbbr, reportingDate}))
        }
    }

    getContent(reportId) {
        return this.props.pageData.get(reportId) || ''
    }

    responsiveLabel(report) {
        return responsiveLabel(report)
    }

    render() {
        if (!this.fullReport) {
            return <div>Loading...</div>
        }
        const tabs = this.makeTabParams(this.props.params)
        return <TabbedReport tabs={tabs} fullReport={this.fullReport} reportContext={this} />
    }

}

/** Generate tab label HTML for a report if shortName is set */
function responsiveLabel(report) {
    if (report.shortName) {
        return [
            <span className="long" key="long">{report.name}</span>,
            <span className="brief" key="brief">{report.shortName}</span>
        ]
    } else {
        return report.name
    }
}
