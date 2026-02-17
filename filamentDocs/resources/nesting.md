# Source: https://filamentphp.com/docs/4.x/resources/nesting

Resources 

# Nested resources 

## [#](#overview)Overview

[Relation managers](managing-relationships#creating-a-relation-manager) and [relation pages](managing-relationships#relation-pages) provide you with an easy way to render a table of related records inside a resource.

For example, in a `CourseResource`, you may have a relation manager or page for `lessons` that belong to that course. You can create and edit lessons from the table, which opens modal dialogs.

However, lessons may be too complex to be created and edited in a modal. You may wish that lessons had their own resource, so that creating and editing them would be a full page experience. This is a nested resource.

## [#](#creating-a-nested-resource)Creating a nested resource

To create a nested resource, you can use the `make:filament-resource` command with the `--nested` option:

 php artisan make:filament-resource Lesson --nested

To access the nested resource, you will also need a [relation manager](managing-relationships#creating-a-relation-manager) or [relation page](managing-relationships#relation-pages). This is where the user can see the list of related records, and click links to the “create” and “edit” pages.

To create a relation manager or page, you can use the `make:filament-relation-manager` or `make:filament-page` command:

 php artisan make:filament-relation-manager CourseResource lessons title
 
 php artisan make:filament-page ManageCourseLessons --resource=CourseResource --type=ManageRelatedRecords

When creating a relation manager or page, Filament will ask if you want each table row to link to a resource instead of opening a modal, to which you should answer “yes” and select the nested resource that you just created.

After generating the relation manager or page, it will have a property pointing to the nested resource:

 use App\Filament\Resources\Courses\Resources\Lessons\LessonResource;
 
 protected static ?string $relatedResource = LessonResource::class;

The nested resource class will have a property pointing to the parent resource:

 use App\Filament\Resources\Courses\CourseResource;
 
 protected static ?string $parentResource = CourseResource::class;

## [#](#customizing-the-relationship-names)Customizing the relationship names

In the same way that relation managers and pages predict the name of relationships based on the models in those relationships, nested resources do the same. Sometimes, you may have a relationship that does not fit the traditional relationship naming convention, and you will need to inform Filament of the correct relationship names for the nested resource.

To customize the relationship names, first remove the `$parentResource` property from the nested resource class. Then define a `getParentResourceRegistration()` method:

 use App\Filament\Resources\Courses\CourseResource;
 use Filament\Resources\ParentResourceRegistration;
 
 public static function getParentResourceRegistration(): ?ParentResourceRegistration
 {
 return CourseResource::asParent()
 ->relationship('lessons')
 ->inverseRelationship('course');
 }

You can omit the calls to `relationship()` and `inverseRelationship()` if you want to use the default names.

## [#](#registering-a-relation-manager-with-the-correct-url)Registering a relation manager with the correct URL

When dealing with a nested resource that is listed by a relation manager, and the relation manager is amongst others on that page, you may notice that the URL to it is not correct when you redirect from the nested resource back to it. This is because each relation manager registered on a resource is assigned an integer, which is used to identify it in the URL when switching between multiple relation managers. For example, `?relation=0` might represent one relation manager in the URL, and `?relation=1` might represent another.

When redirecting from a nested resource back to a relation manager, Filament will assume that the relationship name is used to identify that relation manager in the URL. For example, if you have a nested `LessonResource` and a `LessonsRelationManager`, the relationship name is `lessons`, and should be used as the [URL parameter key](managing-relationships#customizing-the-relation-managers-url-parameter) for that relation manager when it is registered:

 public static function getRelations(): array
 {
 return [
 'lessons' => LessonsRelationManager::class,
 ];
 }

[Edit on GitHub](https://github.com/filamentphp/filament/edit/4.x/docs/03-resources/08-nesting.md)

Still need help? Join our [Discord community](/discord) or open a [GitHub discussion](https://github.com/filamentphp/filament/discussions/new/choose)