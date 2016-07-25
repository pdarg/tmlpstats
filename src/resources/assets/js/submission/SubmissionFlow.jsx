import React from 'react'
import { Route, IndexRedirect } from 'react-router'
import * as Pages from './pages'

export default function SubmissionFlow() {
    return (
        <Route path="/center/:centerId/submission/:reportingDate" component={Pages.SubmissionFlowRoot}>
            <IndexRedirect to="scoreboard" />
            <Route path="scoreboard" component={Pages.Scoreboard} />
            <Route path="applications" component={Pages.ApplicationsIndex} />
            <Route path="applications/edit/:appId" component={Pages.ApplicationsEdit} />
            <Route path="applications/add" component={Pages.ApplicationsAdd} />
            <Route path="classlist" component={Pages.ClassList} />
            <Route path="courses" component={Pages.CoursesIndex} />
            <Route path="courses/edit/:courseId" component={Pages.CoursesEdit} />
            <Route path="courses/add" component={Pages.CoursesAdd} />
            <Route path="review" component={Pages.Review} />
        </Route>
    )
}
