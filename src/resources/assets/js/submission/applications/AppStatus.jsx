import { React } from '../base_components'
import { Field, SimpleSelect } from '../../reusable/form_utils'
import { setAppStatus } from './actions'

const STATUS_UNKNOWN=0,
    STATUS_REG=1,
    STATUS_OUT=2,
    STATUS_IN=3,
    STATUS_APPR=4,
    STATUS_WD=5

// Since the status is not stored currently inside the model, we will just infer it from the existing properties
export function inferStatus(app) {
    if (app.appStatus) {
        return app.appStatus
    }
    if (app.wdDate) {
        return STATUS_WD
    }
    if (app.apprDate) {
        return STATUS_APPR
    }
    if (app.appInDate) {
        return STATUS_IN
    }
    if (app.appOutDate) {
        return STATUS_OUT
    }
    if (app.regDate) {
        return STATUS_REG
    }
    return STATUS_UNKNOWN
}

var STATUS_BEHAVIOUR = [
    {status: STATUS_REG, key: 'regDate', title: 'Registered'},
    {status: STATUS_OUT, key: 'appOutDate', title: 'App Out'},
    {status: STATUS_IN, key: 'appInDate', title: 'App In'},
    {status: STATUS_APPR, key: 'apprDate', title: 'Approved', dateTitle: 'Date Approved'},
    {status: STATUS_WD, key: 'wdDate', title: 'Withdrawn', dateTitle: 'Withdraw Date', renderGroup: 'renderWithdrawn'}
]

export default class AppStatus extends React.Component {
    render() {
        const { model, currentApp, dispatch } = this.props
        var currentStatus = inferStatus(currentApp)

        var buttons = []
        var statuses = []
        STATUS_BEHAVIOUR.forEach((item) => {
            var disabled = (item.status > currentStatus)
            var classes = 'btn'
            if (disabled) {
                classes += ' btn-default'
            } else {
                classes += ' btn-success'
            }
            if (item.status == currentStatus) {
                classes += ' active'
            }
            let clickHandler = () => dispatch(setAppStatus(item.status))
            buttons.push(
                <button key={item.key} type="button" className={classes} onClick={clickHandler}>{item.title}</button>
            )
            statuses.push(this[item.renderGroup || 'renderGroup']({
                disabled: disabled,
                dateTitle: item.dateTitle || (item.title + ' Date'),
                item: item,
                model: model
            }))
        })
        return (
            <div className="panel panel-default">
                <div className="panel-body">
                    <div className="row">
                        <div className="col-sm-offset-1 col-sm-11">
                            <div className="btn-group" style={{paddingBottom: '15px'}}>{buttons}</div>
                        </div>
                    </div>
                    <div>
                        {statuses}
                    </div>
                </div>
            </div>
        )
    }

    renderWithdrawn({ item, model, dateTitle, disabled }) {
        if (disabled) {
            return <div key={item.key}></div>
        }
        return (
            <div className="row" key={item.key}>
                <label className="control-label col-sm-3">{dateTitle}</label>
                <div className="col-sm-6">
                    <Field model={model+'.'+item.key}>
                        <label>Withdraw Date</label>
                        <input type="text" className="form-control" />
                    </Field>
                    <br />
                    <SimpleSelect model={model+'.withdrawCode'} keyProp="id" labelProp="display" items={this.props.lookups.withdraw_codes} emptyChoice="Choose One" />
                </div>
            </div>
        )
    }

    renderGroup({ item, model, dateTitle, disabled }) {
        var style = {}
        if (disabled) {
            style['visibility'] = 'hidden'
        }
        return (
            <div className="form-group" key={item.key} style={style}>
                <Field model={model+'.'+item.key}>
                    <label className="control-label col-sm-3">{dateTitle}</label>
                    <div className="col-sm-6">
                        <input type="text" className="form-control" disabled={disabled} aria-describedby={'addon-apps'+item.key} />
                    </div>
                </Field>
            </div>
        )
    }
}
