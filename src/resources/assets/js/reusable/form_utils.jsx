import React, { PropTypes } from 'react'
import { Form, Field, actions as formActions } from 'react-redux-form'
import { Link } from 'react-router'

export { Form, Field, formActions }

export class SimpleField extends React.Component {
    static defaultProps = {
        labelClass: 'col-md-2',
        divClass: 'col-md-8'
    }
    render() {
        var field
        if (this.props.customField) {
            field = this.props.children
        } else if (this.props.disabled) {
            field = <input type="text" className="form-control" disabled={this.props.disabled} />
        } else {
            field = <input type="text" className="form-control" />
        }

        const { labelClass, divClass } = this.props

        return (
            <Field model={this.props.model}>
                <div className="form-group">
                    <label className={labelClass + ' control-label'}>{this.props.label}</label>
                    <div className={divClass}>{field}</div>
                </div>
            </Field>
        )
    }
}

export class AddOneLink extends React.Component {
    render() {

        var label = this.props.label
        if (!label) {
            label = '+ Add One'
        }

        return (
            <Link to={this.props.link}>{label}</Link>
        )
    }
}

export class SimpleSelect extends React.Component {
    static defaultProps = {
        keyProp: 'key',
        labelProp: 'label',
        multiple: false,
        rows: 1
    }
    static propTypes = {
        items: PropTypes.arrayOf(PropTypes.object).isRequired,
        keyProp: PropTypes.string,
        getKey: PropTypes.func,
        labelProp: PropTypes.string,
        getLabel: PropTypes.func,
        selectClasses: PropTypes.string,
        multiple: PropTypes.bool,
        rows: PropTypes.number
    }
    render() {
        const items = this.props.items || []
        let { getKey, getLabel, emptyChoice } = this.props
        if (!getKey) {
            getKey = (obj) => obj[this.props.keyProp]
        }
        if (!getLabel) {
            getLabel = (obj) => obj[this.props.labelProp]
        }

        const options = []
        if (emptyChoice) {
            options.push(<option key={-1} value="">{emptyChoice}</option>)
        }
        items.forEach((item, i) => {
            options.push(
                <option key={i} value={getKey(item)}>{getLabel(item)}</option>
            )
        })
        return (
            <Field model={this.props.model} multiple={this.props.multiple} rows={this.props.rows}>
                <select className="form-control">{options}</select>
            </Field>
        )
    }
}
