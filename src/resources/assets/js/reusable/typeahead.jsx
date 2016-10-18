/**
 * Helpers for doing the typeahead pattern in a bootstrappy way.
 *
 * We are also trying to do a minimal use case of react-typeahead here, because
 * the long term desire here is to move to a controlled component plus a higher
 * order component, both of which are intended to work with redux, and the latter
 * to work with react-redux-form.
 */
import React, { PureComponent } from 'react'
import RTypeahead from 'react-bootstrap-typeahead'


export class Typeahead extends PureComponent {
    static defaultProps = {
    }
    render() {
        return <RTypeahead {...this.props} />
    }
}
