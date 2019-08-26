const gulp = require('gulp');
const concatCss = require('gulp-concat');
const cssPurge = require('css-purge');


gulp.task('concat', () => {
    return gulp.src('test/custom/module/*.css')
        .pipe(concatCss("styles.css"))
        .pipe(gulp.dest('css'));
});

gulp.task('purge', () => {
    return cssPurge.purgeCSSFiles({
    });
});