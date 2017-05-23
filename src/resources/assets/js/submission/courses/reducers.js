import { combineReducers } from 'redux'
import { modelReducer, formReducer } from 'react-redux-form'

import { coursesCollection, coursesLoad, saveCourseLoad, messages } from './data'

const COURSES_BASE_KEY = 'submission.courses'
export const COURSES_FORM_KEY = COURSES_BASE_KEY + '.currentCourse'


export const courseReducer = combineReducers({
    loading: coursesLoad.reducer(),
    courses: coursesCollection.reducer(),
    currentCourse: modelReducer(COURSES_FORM_KEY, []),
    forms: formReducer(COURSES_BASE_KEY, []),
    saveCourse: saveCourseLoad.reducer(),
    messages: messages.reducer(),
})
